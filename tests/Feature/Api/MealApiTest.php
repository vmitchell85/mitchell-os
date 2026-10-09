<?php

use App\Models\HouseholdSetting;
use App\Models\Meal;
use App\Models\User;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Process;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = Sanctum::actingAs(User::factory()->create());
});

it('lists the meals between two dates by day, in breakfast, lunch, dinner order', function () {
    Meal::factory()->create(['date' => '2026-10-13', 'category' => 'Dinner', 'name' => 'Curry']);
    Meal::factory()->create(['date' => '2026-10-13', 'category' => 'Breakfast', 'name' => 'Porridge']);
    Meal::factory()->draft()->create(['date' => '2026-10-18', 'category' => 'Dinner', 'name' => 'Roast', 'description' => "Chicken\nPotatoes"]);
    Meal::factory()->create(['date' => '2026-10-19', 'name' => 'Next week']);

    $response = $this->getJson(route('api.v1.meals.index', ['from' => '2026-10-12', 'to' => '2026-10-18']));

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.name', 'Porridge')
        ->assertJsonPath('data.1.name', 'Curry')
        ->assertJsonPath('data.2.is_draft', true)
        ->assertJsonPath('data.2.ingredients', ['Chicken', 'Potatoes']);
});

it('lists this week\'s meals by default', function () {
    $this->travelTo('2026-10-15 12:00');
    Meal::factory()->create(['date' => '2026-10-12', 'name' => 'Monday']);
    Meal::factory()->create(['date' => '2026-10-11', 'name' => 'Last Sunday']);

    $this->getJson(route('api.v1.meals.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Monday');
});

it('adds a meal as approved, recording who added it', function () {
    $response = $this->postJson(route('api.v1.meals.store'), [
        'date' => '2026-10-14',
        'category' => 'Dinner',
        'name' => 'Lasagne',
        'ingredients' => ['500g beef mince', ' ', 'Lasagne sheets'],
    ]);

    $response->assertCreated()->assertJsonPath('data.is_draft', false);
    expect(Meal::sole())
        ->description->toBe("500g beef mince\nLasagne sheets")
        ->added_by->toBe($this->user->id);
});

it('validates a meal', function () {
    $this->postJson(route('api.v1.meals.store'), ['date' => 'soon', 'category' => 'Supper'])
        ->assertJsonValidationErrors(['date', 'category', 'name']);
});

it('updates only the fields given', function () {
    $meal = Meal::factory()->create(['name' => 'Curry', 'note' => 'Mild', 'description' => 'Rice']);

    $this->patchJson(route('api.v1.meals.update', $meal), ['name' => 'Thai curry', 'note' => null])->assertOk();

    expect($meal->fresh())
        ->name->toBe('Thai curry')
        ->note->toBeNull()
        ->description->toBe('Rice');
});

it('soft-deletes a removed meal so sync removes it from skylight, but deletes a draft outright', function () {
    $meal = Meal::factory()->create();
    $draft = Meal::factory()->draft()->create();

    $this->deleteJson(route('api.v1.meals.destroy', $meal))->assertNoContent();
    $this->deleteJson(route('api.v1.meals.destroy', $draft))->assertNoContent();

    expect($meal->fresh()->trashed())->toBeTrue();
    expect(Meal::withTrashed()->find($draft->id))->toBeNull();
});

it('approves a suggestion', function () {
    $draft = Meal::factory()->draft()->create();

    $this->postJson(route('api.v1.meals.approve', $draft))->assertOk()->assertJsonPath('data.is_draft', false);

    expect($draft->fresh()->isDraft())->toBeFalse();
});

it('plans a week\'s empty slots with claude as drafts, following the food rules', function () {
    HouseholdSetting::put(HouseholdSetting::FoodRules, 'No seafood.');
    $captured = fakeClaudeProcess(claudeStructuredResult(['meals' => [
        ['date' => '2026-10-12', 'category' => 'Dinner', 'name' => 'Chilli', 'ingredients' => ['Beans'], 'note' => ''],
    ]]));

    $response = $this->postJson(route('api.v1.meal-plans.store'), ['week' => '2026-10-14', 'categories' => ['Dinner']]);

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Chilli')
        ->assertJsonPath('data.0.is_draft', true);
    expect($captured->process->input)->toContain('No seafood.');
});

it('reports claude failing to plan as a bad gateway', function () {
    Exceptions::fake();
    fakeClaudeProcess(exitCode: 1, errorOutput: 'Not logged in');

    $this->postJson(route('api.v1.meal-plans.store'), ['week' => '2026-10-12'])
        ->assertStatus(502)
        ->assertJsonPath('message', 'Claude exited with code 1: Not logged in');
});

it('only regenerates suggestions, not approved meals', function () {
    fakeClaudeProcess();
    $meal = Meal::factory()->create();

    $this->postJson(route('api.v1.meals.regenerate', $meal))->assertConflict();

    Process::assertNothingRan();
});

it('regenerates a suggestion', function () {
    $draft = Meal::factory()->draft()->create(['date' => '2026-10-12', 'category' => 'Dinner', 'name' => 'Chilli']);
    fakeClaudeProcess(claudeStructuredResult(['meals' => [
        ['date' => '2026-10-12', 'category' => 'Dinner', 'name' => 'Tacos', 'ingredients' => [], 'note' => ''],
    ]]));

    $this->postJson(route('api.v1.meals.regenerate', $draft), ['notes' => 'Something quick'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Tacos');
});
