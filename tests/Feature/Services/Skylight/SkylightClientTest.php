<?php

use App\Services\Mcp\McpException;
use App\Services\Skylight\SkylightClient;
use App\Services\Skylight\SkylightListItem;
use App\Services\Skylight\SkylightMealCategory;
use Carbon\CarbonImmutable;
use Tests\Fakes\FakeMcpClient;

it('returns only shopping lists, flagging the default grocery list', function () {
    $client = new SkylightClient(new FakeMcpClient(['skylight_list_lists' => skylightFixture('lists')]));

    $lists = $client->shoppingLists();

    expect(array_map(fn ($list) => [$list->id, $list->label, $list->isDefaultGroceryList], $lists))
        ->toBe([['9002', 'Groceries', true], ['9003', 'Costco', false]]);
});

it('reads list items with their completion, section and position', function () {
    $mcp = new FakeMcpClient(['skylight_get_list_items' => skylightFixture('list-items')]);

    $items = (new SkylightClient($mcp))->listItems('9002');

    expect($items)->toEqual([
        new SkylightListItem('7001', 'Milk', isCompleted: false, section: 'Dairy', position: 1),
        new SkylightListItem('7002', 'Bananas', isCompleted: true, section: 'Produce', position: 2),
        new SkylightListItem('7003', 'Bin bags', isCompleted: false, section: null, position: 3),
    ]);
    expect($mcp->argumentsFor('skylight_get_list_items'))->toBe([['listId' => '9002']]);
});

it('adds an item without a section in a single call', function () {
    $mcp = new FakeMcpClient(['skylight_add_list_item' => [...skylightFixture('list-items')[2], 'label' => 'Bin bags']]);

    $item = (new SkylightClient($mcp))->addListItem('9002', 'Bin bags');

    expect($item->id)->toBe('7003');
    expect(array_column($mcp->calls, 'name'))->toBe(['skylight_add_list_item']);
});

it('adds an item and then sets its section, since adding cannot', function () {
    $added = [...skylightFixture('list-items')[0], 'section' => null];
    $mcp = new FakeMcpClient([
        'skylight_add_list_item' => $added,
        'skylight_update_list_item' => fn (array $arguments) => [...$added, 'section' => $arguments['section']],
    ]);

    $item = (new SkylightClient($mcp))->addListItem('9002', 'Milk', 'Dairy');

    expect($item->section)->toBe('Dairy');
    expect($mcp->argumentsFor('skylight_add_list_item'))->toBe([['listId' => '9002', 'label' => 'Milk']]);
    expect($mcp->argumentsFor('skylight_update_list_item'))->toBe([['listId' => '9002', 'itemId' => '7001', 'section' => 'Dairy']]);
});

it('sends only the item fields that changed, translating completion to checked', function () {
    $mcp = new FakeMcpClient(['skylight_update_list_item' => skylightFixture('list-items')[1]]);

    (new SkylightClient($mcp))->updateListItem('9002', '7002', ['isCompleted' => true, 'section' => null]);

    expect($mcp->argumentsFor('skylight_update_list_item'))
        ->toBe([['listId' => '9002', 'itemId' => '7002', 'checked' => true, 'section' => null]]);
});

it('reads meals, naming recipe-linked meals after their recipe', function () {
    $mcp = new FakeMcpClient(['skylight_list_meals' => skylightFixture('meals')]);

    [$bolognese, $fishAndChips] = (new SkylightClient($mcp))->meals(CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-18'));

    expect($bolognese)
        ->name->toBe('Spaghetti bolognese')
        ->note->toBe('Double batch')
        ->isRecurring->toBeFalse()
        ->dates->toBe(['2026-10-12']);
    expect($bolognese->category)->toEqual(new SkylightMealCategory('503', 'Dinner', isEnabled: true));
    expect($fishAndChips)
        ->name->toBe('Sloppy joes')
        ->recipeId->toBe('601')
        ->isRecurring->toBeTrue()
        ->dates->toBe(['2026-10-09', '2026-10-16']);
    expect($mcp->argumentsFor('skylight_list_meals'))->toBe([['date_min' => '2026-10-05', 'date_max' => '2026-10-18']]);
});

it('plans a meal without sending empty optional fields', function () {
    $mcp = new FakeMcpClient(['skylight_plan_meal' => skylightFixture('meals')[0]]);

    $meal = (new SkylightClient($mcp))->planMeal('503', CarbonImmutable::parse('2026-10-12'), 'Spaghetti bolognese', note: 'Double batch');

    expect($meal->id)->toBe('3001');
    expect($mcp->argumentsFor('skylight_plan_meal'))
        ->toBe([['meal_category_id' => '503', 'date' => '2026-10-12', 'summary' => 'Spaghetti bolognese', 'note' => 'Double batch']]);
});

it('changes and removes only the given occurrence of a meal', function () {
    $mcp = new FakeMcpClient(['skylight_update_meal' => [], 'skylight_delete_meal' => ['deleted' => '3002']]);
    $client = new SkylightClient($mcp);

    $client->updateMeal('3002', CarbonImmutable::parse('2026-10-16'), ['name' => 'Fish pie', 'date' => CarbonImmutable::parse('2026-10-17')]);
    $client->deleteMeal('3002', CarbonImmutable::parse('2026-10-09'));

    expect($mcp->argumentsFor('skylight_update_meal'))
        ->toBe([['id' => '3002', 'instance_date' => '2026-10-16', 'apply_to' => 'one', 'summary' => 'Fish pie', 'date' => '2026-10-17']]);
    expect($mcp->argumentsFor('skylight_delete_meal'))
        ->toBe([['id' => '3002', 'instance_date' => '2026-10-09', 'apply_to' => 'one']]);
});

it('reads meal categories including disabled ones', function () {
    $client = new SkylightClient(new FakeMcpClient(['skylight_list_meal_categories' => skylightFixture('meal-categories')]));

    expect($client->mealCategories())->toEqual([
        new SkylightMealCategory('501', 'Breakfast', isEnabled: true),
        new SkylightMealCategory('502', 'Lunch', isEnabled: true),
        new SkylightMealCategory('503', 'Dinner', isEnabled: true),
        new SkylightMealCategory('504', 'Sides', isEnabled: false),
    ]);
});

it('throws a clear error when skylight returns something other than records', function () {
    $client = new SkylightClient(new FakeMcpClient(['skylight_get_list_items' => 'Skylight is down for maintenance']));

    $client->listItems('9002');
})->throws(McpException::class, "Skylight's [skylight_get_list_items] tool returned something other than a list of records.");
