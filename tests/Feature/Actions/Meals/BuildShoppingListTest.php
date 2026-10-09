<?php

use App\Actions\Meals\BuildShoppingList;
use App\Models\ClaudeRun;
use App\Models\HouseholdSetting;
use App\Models\Meal;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;

function buildShoppingList(ShoppingList $list, ?User $user = null): array
{
    return app(BuildShoppingList::class)->handle($user ?? User::factory()->create(), $list, CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-10-18'));
}

it('gives claude the approved meals in range, what is on the list and its sections', function () {
    $user = User::factory()->create();
    $list = ShoppingList::factory()->create();
    ShoppingListItem::factory()->for($list)->create(['name' => 'Semi-skimmed milk', 'section' => 'Dairy']);
    ShoppingListItem::factory()->for($list)->completed()->create(['name' => 'Bananas', 'section' => 'Produce']);
    Meal::factory()->create(['date' => '2026-10-12', 'name' => 'Bolognese', 'description' => "500g beef mince\n1 tin tomatoes"]);
    Meal::factory()->create(['date' => '2026-10-16', 'name' => 'Fish and chips', 'description' => null]);
    Meal::factory()->draft()->create(['date' => '2026-10-13', 'name' => 'Unapproved lasagne']);
    Meal::factory()->create(['date' => '2026-10-20', 'name' => 'Next week roast']);
    $captured = fakeClaudeProcess(claudeStructuredResult(['items' => []]));

    buildShoppingList($list, $user);

    expect($captured->process->input)
        ->toContain("- Monday 12 October, Dinner: Bolognese\n  Ingredients: 500g beef mince; 1 tin tomatoes")
        ->toContain("- Friday 16 October, Dinner: Fish and chips\n  Ingredients: not listed")
        ->toContain("Already on the list:\n- Semi-skimmed milk")
        ->toContain("The list's sections: Dairy, Produce")
        ->not->toContain('Unapproved lasagne')
        ->not->toContain('Next week roast')
        ->not->toContain('- Bananas');
    expect(ClaudeRun::sole())->feature->toBe('shopping-list-builder')->user_id->toBe($user->id);
});

it('returns proposals without blanks or anything already on the list', function () {
    $list = ShoppingList::factory()->create();
    ShoppingListItem::factory()->for($list)->create(['name' => 'Milk']);
    Meal::factory()->create(['date' => '2026-10-12']);
    fakeClaudeProcess(claudeStructuredResult(['items' => [
        ['name' => ' 1kg beef mince ', 'section' => 'Meat', 'meals' => ['Bolognese', ' Chilli ']],
        ['name' => 'milk', 'section' => 'Dairy', 'meals' => ['Porridge']],
        ['name' => '1KG BEEF MINCE', 'section' => 'Meat', 'meals' => []],
        ['name' => '', 'section' => 'Other', 'meals' => []],
        ['name' => 'Tortillas', 'section' => '', 'meals' => ['Fajitas']],
    ]]));

    $proposals = buildShoppingList($list);

    expect($proposals)->toBe([
        ['name' => '1kg beef mince', 'section' => 'Meat', 'meals' => ['Bolognese', 'Chilli']],
        ['name' => 'Tortillas', 'section' => null, 'meals' => ['Fajitas']],
    ]);
    expect($list->items()->count())->toBe(1);
});

it('does not ask claude when there are no approved meals in range', function () {
    Meal::factory()->draft()->create(['date' => '2026-10-12']);
    fakeClaudeProcess();

    expect(buildShoppingList(ShoppingList::factory()->create()))->toBe([]);
    Process::assertNothingRan();
});

it('gives claude the household food rules', function () {
    HouseholdSetting::put(HouseholdSetting::FoodRules, 'No seafood.');
    Meal::factory()->create(['date' => '2026-10-12']);
    $captured = fakeClaudeProcess(claudeStructuredResult(['items' => []]));

    buildShoppingList(ShoppingList::factory()->create());

    expect($captured->process->input)->toContain("Household food rules (always follow these):\nNo seafood.");
});
