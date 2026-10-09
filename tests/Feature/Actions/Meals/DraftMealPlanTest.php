<?php

use App\Actions\Meals\DraftMealPlan;
use App\Models\ClaudeRun;
use App\Models\HouseholdSetting;
use App\Models\Meal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->travelTo('2026-10-09 12:00:00');
});

function suggestedMeal(string $date, string $name, string $category = 'Dinner', array $ingredients = ['500g beef mince'], string $note = ''): array
{
    return compact('date', 'category', 'name', 'ingredients', 'note');
}

it('drafts meals for the empty slots only, with ingredients as the description', function () {
    $user = User::factory()->create();
    Meal::factory()->create(['date' => '2026-10-12', 'name' => 'Fish and chips']);
    $captured = fakeClaudeProcess(claudeStructuredResult(['meals' => [
        suggestedMeal('2026-10-13', 'Chicken fajitas', ingredients: ['4 chicken breasts', ' 8 tortillas ', ''], note: '30 minutes'),
        suggestedMeal('2026-10-14', 'Veggie chilli'),
    ]]));

    $drafts = app(DraftMealPlan::class)->handle($user, CarbonImmutable::parse('2026-10-12'), ['Dinner'], 'Sunday vegetarian');

    expect($drafts)->toHaveCount(2);
    expect($drafts->first())
        ->isDraft()->toBeTrue()
        ->name->toBe('Chicken fajitas')
        ->description->toBe("4 chicken breasts\n8 tortillas")
        ->note->toBe('30 minutes')
        ->added_by->toBe($user->id);
    expect($captured->process->input)
        ->toContain('Tuesday 13 October: Dinner (date 2026-10-13)')
        ->not->toContain('Monday 12 October: Dinner')
        ->toContain("Already planned this week:\n- Monday 12 October, Dinner: Fish and chips")
        ->toContain("Notes from the household:\nSunday vegetarian");
    expect(ClaudeRun::sole())->feature->toBe('meal-planner')->user_id->toBe($user->id);
});

it('tells claude what the family ate recently so it can avoid repeats', function () {
    Meal::factory()->create(['date' => '2026-10-02', 'name' => 'Roast chicken']);
    Meal::factory()->draft()->create(['date' => '2026-10-03', 'name' => 'Rejected idea']);
    $captured = fakeClaudeProcess(claudeStructuredResult(['meals' => []]));

    app(DraftMealPlan::class)->handle(User::factory()->create(), CarbonImmutable::parse('2026-10-12'), ['Dinner']);

    expect($captured->process->input)
        ->toContain("Eaten in the last four weeks:\n- Roast chicken")
        ->not->toContain('Rejected idea');
});

it('ignores suggestions for slots that were not asked for or are already filled', function () {
    Meal::factory()->create(['date' => '2026-10-12']);
    fakeClaudeProcess(claudeStructuredResult(['meals' => [
        suggestedMeal('2026-10-12', 'Duplicate dinner'),
        suggestedMeal('2026-10-13', 'Porridge', category: 'Breakfast'),
        suggestedMeal('2026-10-30', 'Wrong week'),
        suggestedMeal('2026-10-13', 'Lasagne'),
        suggestedMeal('2026-10-13', 'Second lasagne'),
    ]]));

    $drafts = app(DraftMealPlan::class)->handle(User::factory()->create(), CarbonImmutable::parse('2026-10-12'), ['Dinner']);

    expect($drafts->pluck('name')->all())->toBe(['Lasagne']);
});

it('does not ask claude when every requested slot is already planned', function () {
    foreach (range(12, 18) as $day) {
        Meal::factory()->create(['date' => "2026-10-{$day}"]);
    }
    fakeClaudeProcess();

    $drafts = app(DraftMealPlan::class)->handle(User::factory()->create(), CarbonImmutable::parse('2026-10-12'), ['Dinner']);

    expect($drafts)->toBeEmpty();
    Process::assertNothingRan();
});

it('gives claude the household food rules', function () {
    HouseholdSetting::put(HouseholdSetting::FoodRules, 'No seafood.');
    $captured = fakeClaudeProcess(claudeStructuredResult(['meals' => []]));

    app(DraftMealPlan::class)->handle(User::factory()->create(), CarbonImmutable::parse('2026-10-12'), ['Dinner']);

    expect($captured->process->input)->toContain("Household food rules (always follow these):\nNo seafood.");
});
