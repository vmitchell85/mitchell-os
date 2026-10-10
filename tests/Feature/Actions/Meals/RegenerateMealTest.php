<?php

use App\Actions\Meals\RegenerateMeal;
use App\Models\Meal;
use App\Models\User;
use App\Services\Claude\ClaudeException;

it('replaces a draft with a different suggestion for the same slot', function () {
    $draft = Meal::factory()->draft()->create(['date' => '2026-10-13', 'name' => 'Lasagna', 'note' => 'Bake ahead']);
    $captured = fakeClaudeProcess(claudeStructuredResult(['meals' => [
        ['date' => '2026-10-13', 'category' => 'Dinner', 'name' => 'Stir fry', 'ingredients' => ['Noodles', 'Pak choi'], 'note' => ''],
    ]]));

    app(RegenerateMeal::class)->handle(User::factory()->create(), $draft, 'Something quick');

    expect($draft->fresh())
        ->name->toBe('Stir fry')
        ->description->toBe("Noodles\nPak choi")
        ->note->toBeNull()
        ->isDraft()->toBeTrue();
    expect($captured->process->input)
        ->toContain('Suggest one Dinner for Tuesday, October 13 (date 2026-10-13). It must be something other than Lasagna.')
        ->not->toContain('Already planned this week')
        ->toContain("Notes from the household:\nSomething quick");
});

it('throws and leaves the draft alone when claude suggests nothing', function () {
    $draft = Meal::factory()->draft()->create(['name' => 'Lasagna']);
    fakeClaudeProcess(claudeStructuredResult(['meals' => []]));

    expect(fn () => app(RegenerateMeal::class)->handle(User::factory()->create(), $draft))
        ->toThrow(ClaudeException::class, 'Claude did not suggest a meal.');

    expect($draft->fresh()->name)->toBe('Lasagna');
});
