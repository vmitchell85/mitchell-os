<?php

use App\Actions\Meals\DraftMealPlan;
use App\Models\ClaudeRun;
use App\Models\HouseholdNote;
use App\Models\HouseholdSetting;
use App\Models\Meal;
use App\Models\Recipe;
use App\Models\SharedCalendar;
use App\Models\User;
use App\Services\Calendar\CalendarException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->travelTo('2026-10-09 12:00:00');
});

function suggestedMeal(string $date, string $name, string $category = 'Dinner', array $ingredients = ['1 lb ground beef'], string $note = ''): array
{
    return compact('date', 'category', 'name', 'ingredients', 'note');
}

it('drafts meals for the empty slots only, with ingredients as the description', function () {
    $user = User::factory()->create();
    Meal::factory()->create(['date' => '2026-10-12', 'name' => 'Sloppy joes']);
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
        ->toContain('Tuesday, October 13: Dinner (date 2026-10-13)')
        ->not->toContain('Monday, October 12: Dinner')
        ->toContain("Already planned this week:\n- Monday, October 12, Dinner: Sloppy joes")
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
        suggestedMeal('2026-10-13', 'Lasagna'),
        suggestedMeal('2026-10-13', 'Second lasagna'),
    ]]));

    $drafts = app(DraftMealPlan::class)->handle(User::factory()->create(), CarbonImmutable::parse('2026-10-12'), ['Dinner']);

    expect($drafts->pluck('name')->all())->toBe(['Lasagna']);
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

it('tells claude what is on the shared calendars that week', function () {
    SharedCalendar::factory()->create(['calendar_id' => 'KIDS-1', 'label' => 'Kids']);
    $helper = fakeCalendarHelper(events: [
        ['calendar_id' => 'KIDS-1', 'title' => 'Football practice', 'all_day' => false, 'start' => '2026-10-13T17:30:00-04:00', 'end' => '2026-10-13T19:00:00-04:00', 'location' => null],
    ]);
    $captured = fakeClaudeProcess(claudeStructuredResult(['meals' => []]));

    app(DraftMealPlan::class)->handle(User::factory()->create(), CarbonImmutable::parse('2026-10-12'), ['Dinner']);

    expect($captured->process->input)->toContain("On the family's calendar this week:\n- Tue, Oct 13, 5:30 PM–7:00 PM: Football practice (Kids)");
    expect(claudeOption($captured->process->command, '--system-prompt'))->toContain('suggest something quick on busy evenings');
    expect($helper->runs[0][1])->toStartWith('2026-10-12T00:00:00');
});

it('plans without the calendar when it cannot be read', function () {
    Exceptions::fake();
    SharedCalendar::factory()->create();
    fakeCalendarHelper(error: 'Calendar access is turned off.');
    $captured = fakeClaudeProcess(claudeStructuredResult(['meals' => []]));

    app(DraftMealPlan::class)->handle(User::factory()->create(), CarbonImmutable::parse('2026-10-12'), ['Dinner']);

    expect($captured->process->input)->not->toContain("family's calendar");
    Exceptions::assertReported(CalendarException::class);
});

it('gives claude the household notes', function () {
    HouseholdNote::factory()->create(['body' => 'We have soccer practice on Tuesdays.']);
    $captured = fakeClaudeProcess(claudeStructuredResult(['meals' => []]));

    app(DraftMealPlan::class)->handle(User::factory()->create(), CarbonImmutable::parse('2026-10-12'), ['Dinner']);

    expect($captured->process->input)->toContain("About the household (keep these in mind):\n- We have soccer practice on Tuesdays.");
});

it('offers claude the saved recipes and uses a picked recipe\'s own name and ingredients', function () {
    $recipe = Recipe::factory()->create(['name' => 'Beef chili', 'ingredients' => "1 lb ground beef\n1 can kidney beans", 'total_minutes' => 45]);
    $captured = fakeClaudeProcess(claudeStructuredResult(['meals' => [
        [...suggestedMeal('2026-10-12', 'Chili', ingredients: ['beef']), 'recipe_id' => $recipe->id],
        [...suggestedMeal('2026-10-13', 'Chicken tacos'), 'recipe_id' => 0],
        [...suggestedMeal('2026-10-14', 'Pot roast'), 'recipe_id' => 999],
    ]]));

    $drafts = app(DraftMealPlan::class)->handle(User::factory()->create(), CarbonImmutable::parse('2026-10-12'), ['Dinner']);

    expect($captured->process->input)->toContain("The family's saved recipes (id: name):\n- {$recipe->id}: Beef chili (45 min)");
    expect($drafts[0])
        ->name->toBe('Beef chili')
        ->description->toBe("1 lb ground beef\n1 can kidney beans")
        ->recipe_id->toBe($recipe->id);
    expect($drafts[1]->recipe_id)->toBeNull();
    expect($drafts[2])->name->toBe('Pot roast')->recipe_id->toBeNull();
});
