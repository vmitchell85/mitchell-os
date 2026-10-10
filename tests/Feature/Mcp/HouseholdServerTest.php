<?php

use App\Mcp\Servers\HouseholdServer;
use App\Mcp\Tools\AddShoppingItemsTool;
use App\Mcp\Tools\GetRecipeTool;
use App\Mcp\Tools\GetShoppingListTool;
use App\Mcp\Tools\ListCalendarEventsTool;
use App\Mcp\Tools\ListMealsTool;
use App\Mcp\Tools\ListRecipesTool;
use App\Mcp\Tools\ListShoppingListsTool;
use App\Mcp\Tools\RememberNoteTool;
use App\Mcp\Tools\SaveRecipeTool;
use App\Mcp\Tools\SuggestMealTool;
use App\Models\HouseholdNote;
use App\Models\Meal;
use App\Models\Recipe;
use App\Models\SharedCalendar;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;

it('names its tools the way chat allows them', function (string $tool, string $claudeName) {
    HouseholdServer::tool($tool, [])->assertName(str($claudeName)->after('mcp__household__')->toString());

    expect(HouseholdServer::ClaudeTools)->toContain($claudeName);
})->with([
    [ListMealsTool::class, 'mcp__household__list-meals'],
    [SuggestMealTool::class, 'mcp__household__suggest-meal'],
    [ListShoppingListsTool::class, 'mcp__household__list-shopping-lists'],
    [GetShoppingListTool::class, 'mcp__household__get-shopping-list'],
    [AddShoppingItemsTool::class, 'mcp__household__add-shopping-items'],
    [ListCalendarEventsTool::class, 'mcp__household__list-calendar-events'],
    [RememberNoteTool::class, 'mcp__household__remember-note'],
    [ListRecipesTool::class, 'mcp__household__list-recipes'],
    [GetRecipeTool::class, 'mcp__household__get-recipe'],
    [SaveRecipeTool::class, 'mcp__household__save-recipe'],
]);

it('lists meals in a date range, marking suggestions awaiting approval', function () {
    Meal::factory()->create(['date' => '2026-10-12', 'name' => 'Bolognese', 'description' => "1 lb ground beef\nSpaghetti", 'note' => '30 minutes']);
    Meal::factory()->draft()->create(['date' => '2026-10-13', 'name' => 'Fajitas']);
    Meal::factory()->create(['date' => '2026-10-20', 'name' => 'Next week roast']);

    $response = HouseholdServer::tool(ListMealsTool::class, ['from' => '2026-10-12', 'to' => '2026-10-18']);

    $response->assertOk()->assertStructuredContent(['meals' => [
        ['id' => Meal::firstWhere('name', 'Bolognese')->id, 'date' => '2026-10-12', 'day' => 'Monday', 'category' => 'Dinner', 'name' => 'Bolognese', 'status' => 'planned', 'ingredients' => ['1 lb ground beef', 'Spaghetti'], 'note' => '30 minutes'],
        ['id' => Meal::firstWhere('name', 'Fajitas')->id, 'date' => '2026-10-13', 'day' => 'Tuesday', 'category' => 'Dinner', 'name' => 'Fajitas', 'status' => 'suggestion awaiting approval', 'ingredients' => [], 'note' => null],
    ]]);
});

it('rejects a meal date range that ends before it starts', function () {
    $response = HouseholdServer::tool(ListMealsTool::class, ['from' => '2026-10-18', 'to' => '2026-10-12']);

    $response->assertHasErrors(['The "to" date must be on or after the "from" date.']);
});

it('saves a suggested meal as a draft attributed to the household member chatting', function () {
    $user = User::factory()->create();

    $response = HouseholdServer::actingAs($user)->tool(SuggestMealTool::class, [
        'date' => '2026-10-14', 'category' => 'Dinner', 'name' => 'Chicken fajitas', 'ingredients' => ['4 chicken breasts', ' ', '8 tortillas'], 'note' => '',
    ]);

    $response->assertOk()->assertSee('Suggested Chicken fajitas for Dinner on Wednesday, October 14.');
    expect(Meal::sole())
        ->isDraft()->toBeTrue()
        ->description->toBe("4 chicken breasts\n8 tortillas")
        ->note->toBeNull()
        ->added_by->toBe($user->id);
});

it('explains which categories a suggested meal can have', function () {
    $response = HouseholdServer::tool(SuggestMealTool::class, ['date' => '2026-10-14', 'category' => 'Supper', 'name' => 'Toast']);

    $response->assertHasErrors(['The category must be one of: Breakfast, Lunch, Dinner.']);
    expect(Meal::count())->toBe(0);
});

it('lists shopping lists, main list first, with what is left to buy', function () {
    $costco = ShoppingList::factory()->create(['name' => 'Costco']);
    $groceries = ShoppingList::factory()->create(['name' => 'Groceries', 'is_default' => true]);
    ShoppingListItem::factory()->for($groceries)->count(2)->create();
    ShoppingListItem::factory()->for($groceries)->completed()->create();

    $response = HouseholdServer::tool(ListShoppingListsTool::class, []);

    $response->assertStructuredContent(['lists' => [
        ['id' => $groceries->id, 'name' => 'Groceries', 'is_default' => true, 'items_to_buy' => 2],
        ['id' => $costco->id, 'name' => 'Costco', 'is_default' => false, 'items_to_buy' => 0],
    ]]);
});

it('gets what is left to buy on the main list by section when no list is given', function () {
    $groceries = ShoppingList::factory()->create(['name' => 'Groceries', 'is_default' => true]);
    ShoppingList::factory()->create(['name' => 'Costco']);
    ShoppingListItem::factory()->for($groceries)->create(['name' => 'Milk', 'section' => 'Dairy', 'position' => 1]);
    ShoppingListItem::factory()->for($groceries)->create(['name' => 'Bin bags', 'position' => 2]);
    ShoppingListItem::factory()->for($groceries)->completed()->create(['name' => 'Bananas', 'section' => 'Produce']);

    $response = HouseholdServer::tool(GetShoppingListTool::class, []);

    $response->assertStructuredContent([
        'list' => ['id' => $groceries->id, 'name' => 'Groceries'],
        'sections' => ['Dairy' => ['Milk'], 'Other' => ['Bin bags']],
    ]);
});

it('tells claude how to find a list when the id is wrong', function () {
    $response = HouseholdServer::tool(GetShoppingListTool::class, ['list_id' => 999]);

    $response->assertHasErrors(['There is no shopping list with that id. Use list-shopping-lists to find one.']);
});

it('adds items to a list, skipping ones already on it, attributed to the household member chatting', function () {
    $user = User::factory()->create();
    $costco = ShoppingList::factory()->create(['name' => 'Costco']);
    ShoppingListItem::factory()->for($costco)->create(['name' => 'Kitchen roll']);

    $response = HouseholdServer::actingAs($user)->tool(AddShoppingItemsTool::class, [
        'list_id' => $costco->id,
        'items' => [['name' => 'kitchen roll'], ['name' => 'Coffee beans', 'section' => 'Drinks']],
    ]);

    $response->assertStructuredContent(['list' => 'Costco', 'added' => ['Coffee beans'], 'already_on_list' => ['kitchen roll']]);
    $this->assertDatabaseHas('shopping_list_items', ['shopping_list_id' => $costco->id, 'name' => 'Coffee beans', 'section' => 'Drinks', 'added_by' => $user->id]);
    expect($costco->items()->count())->toBe(2);
});

it('lists events from the shared calendars, saying whose each is', function () {
    SharedCalendar::factory()->create(['calendar_id' => 'WORK-1', 'title' => 'Work', 'label' => 'Vince – Work']);
    $captured = fakeCalendarHelper(events: [
        ['calendar_id' => 'WORK-1', 'title' => 'Late meeting', 'all_day' => false, 'start' => '2026-10-13T18:00:00-04:00', 'end' => '2026-10-13T19:30:00-04:00', 'location' => 'Office'],
    ]);

    $response = HouseholdServer::tool(ListCalendarEventsTool::class, ['from' => '2026-10-12', 'to' => '2026-10-18']);

    $response->assertOk()->assertStructuredContent(['events' => [
        ['calendar' => 'Vince – Work', 'title' => 'Late meeting', 'when' => 'Tue, Oct 13, 6:00 PM–7:30 PM', 'all_day' => false, 'start' => '2026-10-13T18:00:00-04:00', 'end' => '2026-10-13T19:30:00-04:00', 'location' => 'Office'],
    ]]);
    expect($captured->runs[0][3])->toBe('WORK-1');
});

it('explains when no calendars are shared', function () {
    $captured = fakeCalendarHelper();

    HouseholdServer::tool(ListCalendarEventsTool::class, ['from' => '2026-10-12', 'to' => '2026-10-18'])
        ->assertHasErrors(['The family hasn\'t shared any calendars yet. They can choose some in Settings > Calendars.']);
    expect($captured->runs)->toBe([]);
});

it('says why the calendars could not be read', function () {
    SharedCalendar::factory()->create();
    fakeCalendarHelper(error: 'Calendar access is turned off.');

    HouseholdServer::tool(ListCalendarEventsTool::class, ['from' => '2026-10-12', 'to' => '2026-10-18'])
        ->assertHasErrors(['The family\'s calendars couldn\'t be read: Calendar access is turned off.']);
});

it('saves a note as the household member chatting', function () {
    $user = User::factory()->create();

    HouseholdServer::actingAs($user)->tool(RememberNoteTool::class, ['note' => ' Lucy is allergic to peanuts. '])
        ->assertOk()
        ->assertSee('Saved the note "Lucy is allergic to peanuts."');

    expect(HouseholdNote::sole())->body->toBe('Lucy is allergic to peanuts.')->added_by->toBe($user->id);
});

it('lists and searches the saved recipes', function () {
    $tacos = Recipe::factory()->create(['name' => 'Chicken tacos', 'total_minutes' => 30, 'servings' => '4']);
    Recipe::factory()->create(['name' => 'Beef chili']);

    HouseholdServer::tool(ListRecipesTool::class, ['search' => 'taco'])
        ->assertOk()
        ->assertStructuredContent(['recipes' => [['id' => $tacos->id, 'name' => 'Chicken tacos', 'total_minutes' => 30, 'servings' => '4']]]);
});

it('gets a recipe with its ingredients and steps', function () {
    $recipe = Recipe::factory()->create(['name' => 'Beef chili', 'ingredients' => "1 lb ground beef\nBeans", 'steps' => "Brown.\nSimmer."]);

    HouseholdServer::tool(GetRecipeTool::class, ['recipe_id' => $recipe->id])
        ->assertOk()
        ->assertSee('"ingredients":["1 lb ground beef","Beans"]')
        ->assertSee('"steps":["Brown.","Simmer."]');

    HouseholdServer::tool(GetRecipeTool::class, ['recipe_id' => 999])
        ->assertHasErrors(['There is no saved recipe with that id. Use list-recipes to find one.']);
});

it('saves a recipe as the household member chatting', function () {
    $user = User::factory()->create();

    HouseholdServer::actingAs($user)->tool(SaveRecipeTool::class, [
        'name' => 'Grandma\'s meatloaf', 'ingredients' => ['2 lb ground beef', ' ', '1 cup breadcrumbs'], 'steps' => ['Mix.', 'Bake for an hour.'], 'total_minutes' => 75,
    ])->assertOk()->assertSee('Saved the recipe "Grandma\'s meatloaf"');

    expect(Recipe::sole())
        ->ingredients->toBe("2 lb ground beef\n1 cup breadcrumbs")
        ->steps->toBe("Mix.\nBake for an hour.")
        ->total_minutes->toBe(75)
        ->added_by->toBe($user->id);
});

it('suggests a saved recipe as a meal with its ingredients', function () {
    $recipe = Recipe::factory()->create(['name' => 'Beef chili', 'ingredients' => "1 lb ground beef\nBeans"]);

    HouseholdServer::tool(SuggestMealTool::class, ['date' => '2026-10-14', 'category' => 'Dinner', 'name' => 'Beef chili', 'recipe_id' => $recipe->id])->assertOk();

    expect(Meal::sole())->recipe_id->toBe($recipe->id)->description->toBe("1 lb ground beef\nBeans")->isDraft()->toBeTrue();
});
