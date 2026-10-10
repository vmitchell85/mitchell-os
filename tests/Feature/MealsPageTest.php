<?php

use App\Models\HouseholdSetting;
use App\Models\Meal;
use App\Models\ShoppingList;
use App\Models\User;
use App\Services\Skylight\SkylightClient;
use Livewire\Livewire;
use Tests\Fakes\FakeSkylight;

beforeEach(function () {
    $this->travelTo('2026-10-09 12:00:00');
});

it('redirects guests to the login page', function () {
    $response = $this->get(route('meals'));

    $response->assertRedirect(route('login'));
});

it('shows this week\'s meals by day, in breakfast, lunch, dinner order', function () {
    Meal::factory()->create(['date' => '2026-10-07', 'category' => 'Dinner', 'name' => 'Fish and chips']);
    Meal::factory()->create(['date' => '2026-10-07', 'category' => 'Breakfast', 'name' => 'Pancakes']);
    Meal::factory()->draft()->create(['date' => '2026-10-08', 'name' => 'Lasagne']);
    Meal::factory()->create(['date' => '2026-10-14', 'name' => 'Next week roast']);

    $response = $this->actingAs(User::factory()->create())->get(route('meals'));

    $response->assertOk()
        ->assertSeeInOrder(['5 Oct – 11 Oct 2026', '1 suggestion to review', 'Wednesday 7 October', 'Pancakes', 'Fish and chips', 'Thursday 8 October', 'Lasagne'])
        ->assertDontSee('Next week roast');
});

it('moves between weeks', function () {
    Meal::factory()->create(['date' => '2026-10-14', 'name' => 'Next week roast']);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::meals')
        ->assertSet('week', '2026-10-05')
        ->call('nextWeek')
        ->assertSet('week', '2026-10-12')
        ->assertSee('Next week roast')
        ->call('thisWeek')
        ->assertSet('week', '2026-10-05');
});

it('works out this week and today in eastern time, not utc', function () {
    $this->travelTo('2026-10-12 02:00:00 UTC');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::meals')
        ->assertSet('week', '2026-10-05')
        ->assertSeeInOrder(['Sunday 11 October', 'Today']);
});

it('falls back to this week when the week in the url is not a date', function () {
    Livewire::withQueryParams(['week' => 'not-a-date'])
        ->actingAs(User::factory()->create())
        ->test('pages::meals')
        ->assertSet('week', '2026-10-05');
});

it('adds a meal by hand as approved, recording who added it', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::meals')
        ->call('newMeal', '2026-10-10')
        ->set('mealCategory', 'Lunch')
        ->set('mealName', 'Soup and bread')
        ->set('mealDescription', "1 tin of soup\n1 loaf")
        ->call('saveMeal')
        ->assertHasNoErrors();

    expect(Meal::sole())
        ->date->toDateString()->toBe('2026-10-10')
        ->category->toBe('Lunch')
        ->description->toBe("1 tin of soup\n1 loaf")
        ->isDraft()->toBeFalse()
        ->added_by->toBe($user->id);
});

it('validates a meal before saving it', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::meals')
        ->call('newMeal', '2026-10-10')
        ->set('mealCategory', 'Elevenses')
        ->call('saveMeal')
        ->assertHasErrors(['mealName' => 'required', 'mealCategory' => 'in']);

    expect(Meal::count())->toBe(0);
});

it('edits a meal', function () {
    $meal = Meal::factory()->create(['date' => '2026-10-08', 'name' => 'Fish and chips']);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::meals')
        ->call('edit', $meal->id)
        ->assertSet('mealName', 'Fish and chips')
        ->set('mealName', 'Fish pie')
        ->set('mealDate', '2026-10-09')
        ->call('saveMeal')
        ->assertHasNoErrors();

    expect($meal->fresh())->name->toBe('Fish pie')->date->toDateString()->toBe('2026-10-09');
});

it('soft-deletes a removed meal so sync removes it from skylight, but deletes a draft outright', function () {
    $meal = Meal::factory()->synced()->create(['date' => '2026-10-08']);
    $draft = Meal::factory()->draft()->create(['date' => '2026-10-09']);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::meals')
        ->call('remove', $meal->id)
        ->call('remove', $draft->id);

    $this->assertSoftDeleted($meal);
    $this->assertModelMissing($draft);
});

it('approves one suggestion or all of the week\'s suggestions', function () {
    $first = Meal::factory()->draft()->create(['date' => '2026-10-06']);
    $second = Meal::factory()->draft()->create(['date' => '2026-10-07']);
    $nextWeek = Meal::factory()->draft()->create(['date' => '2026-10-14']);
    $page = Livewire::actingAs(User::factory()->create())->test('pages::meals');

    $page->call('approve', $first->id);
    expect([$first->fresh()->isDraft(), $second->fresh()->isDraft()])->toBe([false, true]);

    $page->call('approveAll');
    expect([$second->fresh()->isDraft(), $nextWeek->fresh()->isDraft()])->toBe([false, true]);
});

it('discards the week\'s suggestions without touching approved meals', function () {
    $draft = Meal::factory()->draft()->create(['date' => '2026-10-06']);
    $approved = Meal::factory()->create(['date' => '2026-10-07']);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::meals')
        ->call('discardDrafts');

    $this->assertModelMissing($draft);
    $this->assertModelExists($approved);
});

it('plans the week with claude and reports how many meals it suggested', function () {
    fakeClaudeProcess(claudeStructuredResult(['meals' => [
        ['date' => '2026-10-06', 'category' => 'Dinner', 'name' => 'Chicken fajitas', 'ingredients' => ['Chicken'], 'note' => ''],
        ['date' => '2026-10-07', 'category' => 'Dinner', 'name' => 'Veggie chilli', 'ingredients' => ['Beans'], 'note' => ''],
    ]]));

    Livewire::actingAs(User::factory()->create())
        ->test('pages::meals')
        ->set('notes', 'Quick weeknights')
        ->call('plan')
        ->assertDispatched('toast-show', fn (string $event, array $params) => $params['slots']['text'] === '2 meals suggested. Review them below.')
        ->assertSee('Chicken fajitas');

    expect(Meal::drafts()->count())->toBe(2);
});

it('shows why planning failed', function () {
    fakeClaudeProcess(exitCode: 1, errorOutput: 'Not logged in');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::meals')
        ->call('plan')
        ->assertDispatched('toast-show', fn (string $event, array $params) => $params['dataset']['variant'] === 'danger'
            && $params['slots']['text'] === 'Claude exited with code 1: Not logged in');

    expect(Meal::count())->toBe(0);
});

it('requires at least one meal of the day to plan', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::meals')
        ->set('planCategories', [])
        ->call('plan')
        ->assertHasErrors(['planCategories' => 'required']);
});

it('only regenerates suggestions, not approved meals', function () {
    $approved = Meal::factory()->create(['date' => '2026-10-07', 'name' => 'Fish and chips']);
    fakeClaudeProcess();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::meals')
        ->call('regenerate', $approved->id)
        ->assertNotFound();

    expect($approved->fresh()->name)->toBe('Fish and chips');
});

it('syncs meals with skylight from the page', function () {
    $skylight = new FakeSkylight;
    $skylight->addMeal('Fish and chips', ['2026-10-09']);
    app()->instance(SkylightClient::class, new SkylightClient($skylight));

    Livewire::actingAs(User::factory()->create())
        ->test('pages::meals')
        ->call('sync')
        ->assertDispatched('toast-show', fn (string $event, array $params) => $params['slots']['text'] === '1 change from Skylight.')
        ->assertSee('Fish and chips');
});

it('builds a shopping list from the week\'s meals and adds only the ticked items', function () {
    $user = User::factory()->create();
    $groceries = ShoppingList::factory()->create(['name' => 'Groceries', 'is_default' => true]);
    Meal::factory()->create(['date' => '2026-10-07', 'name' => 'Bolognese']);
    $captured = fakeClaudeProcess(claudeStructuredResult(['items' => [
        ['name' => '500g beef mince', 'section' => 'Meat', 'meals' => ['Bolognese']],
        ['name' => 'Spaghetti', 'section' => 'Pasta', 'meals' => ['Bolognese']],
    ]]));

    $page = Livewire::actingAs($user)
        ->test('pages::meals')
        ->call('openShoppingList')
        ->assertSet('shoppingListId', $groceries->id)
        ->assertSet('shopFrom', '2026-10-05')
        ->assertSet('shopTo', '2026-10-11')
        ->call('buildShoppingList')
        ->assertSee('500g beef mince')
        ->assertSee('For Bolognese')
        ->set('selectedProposals', ['0'])
        ->call('addProposals')
        ->assertDispatched('toast-show', fn (string $event, array $params) => $params['slots']['text'] === 'Added 1 item to Groceries.');

    expect($groceries->items()->get()->map(fn ($item) => [$item->name, $item->section, $item->added_by])->all())
        ->toBe([['500g beef mince', 'Meat', $user->id]]);
    $page->assertSet('proposals', []);
});

it('requires the shopping list dates to be in order', function () {
    ShoppingList::factory()->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::meals')
        ->call('openShoppingList')
        ->set('shopTo', '2026-10-01')
        ->call('buildShoppingList')
        ->assertHasErrors(['shopTo' => 'after_or_equal']);
});

it('remembers the household food rules when planning', function () {
    HouseholdSetting::put(HouseholdSetting::FoodRules, 'No seafood.');
    $captured = fakeClaudeProcess(claudeStructuredResult(['meals' => []]));

    Livewire::actingAs(User::factory()->create())
        ->test('pages::meals')
        ->assertSet('foodRules', 'No seafood.')
        ->set('foodRules', "No seafood.\nNo nuts.")
        ->call('plan');

    expect(HouseholdSetting::get(HouseholdSetting::FoodRules))->toBe("No seafood.\nNo nuts.");
    expect($captured->process->input)->toContain("No seafood.\nNo nuts.");
});
