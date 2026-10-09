<?php

use App\Models\Meal;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = Sanctum::actingAs(User::factory()->create());
});

it('lists the household\'s lists, main list first, with what\'s left to buy', function () {
    ShoppingList::factory()->create(['name' => 'Aldi']);
    $main = ShoppingList::factory()->create(['name' => 'Costco', 'is_default' => true]);
    ShoppingListItem::factory()->for($main)->count(2)->create();
    ShoppingListItem::factory()->for($main)->completed()->create();

    $response = $this->getJson(route('api.v1.shopping-lists.index'));

    $response->assertOk()
        ->assertJsonPath('data.0.name', 'Costco')
        ->assertJsonPath('data.0.pending_items_count', 2)
        ->assertJsonPath('data.1.name', 'Aldi');
});

it('creates a list to be added to skylight at the next sync, and renames it', function () {
    $response = $this->postJson(route('api.v1.shopping-lists.store'), ['name' => 'Party', 'color' => '#7DB5F5']);

    $response->assertCreated()->assertJsonPath('data.name', 'Party')->assertJsonPath('data.in_skylight', false);
    $list = ShoppingList::sole();
    expect($list->color)->toBe('#7DB5F5');

    $this->patchJson(route('api.v1.shopping-lists.update', $list), ['name' => 'Birthday party'])->assertOk();

    expect($list->fresh()->name)->toBe('Birthday party');
});

it('only accepts skylight\'s list colours', function () {
    $this->postJson(route('api.v1.shopping-lists.store'), ['name' => 'Party', 'color' => 'red'])->assertJsonValidationErrors('color');
});

it('shows a list with its items in order', function () {
    $list = ShoppingList::factory()->create();
    ShoppingListItem::factory()->for($list)->create(['name' => 'Eggs', 'position' => 2]);
    ShoppingListItem::factory()->for($list)->completed()->create(['name' => 'Milk', 'position' => 1]);

    $response = $this->getJson(route('api.v1.shopping-lists.show', $list));

    $response->assertOk()
        ->assertJsonPath('data.items.0.name', 'Milk')
        ->assertJsonPath('data.items.0.completed', true)
        ->assertJsonPath('data.items.1.name', 'Eggs');
});

it('adds an item at the end of the list as the token owner', function () {
    $list = ShoppingList::factory()->create();
    ShoppingListItem::factory()->for($list)->create(['name' => 'Eggs', 'position' => 4]);

    $response = $this->postJson(route('api.v1.shopping-lists.items.store', $list), ['name' => 'Bread', 'section' => 'Bakery']);

    $response->assertCreated()->assertJsonPath('data.section', 'Bakery');
    expect($list->items()->where('name', 'Bread')->sole())
        ->position->toBe(5)
        ->added_by->toBe($this->user->id);
});

it('ticks off, renames and moves an item', function () {
    $item = ShoppingListItem::factory()->create(['name' => 'Milk', 'section' => 'Dairy']);

    $this->patchJson(route('api.v1.shopping-lists.items.update', [$item->shoppingList, $item]), ['completed' => true])
        ->assertOk()
        ->assertJsonPath('data.completed', true);

    $this->patchJson(route('api.v1.shopping-lists.items.update', [$item->shoppingList, $item]), ['name' => 'Oat milk', 'section' => ''])
        ->assertOk();

    expect($item->fresh())
        ->isCompleted()->toBeTrue()
        ->name->toBe('Oat milk')
        ->section->toBeNull();
});

it('soft-deletes a removed item so sync removes it from skylight too', function () {
    $item = ShoppingListItem::factory()->create();

    $this->deleteJson(route('api.v1.shopping-lists.items.destroy', [$item->shoppingList, $item]))->assertNoContent();

    expect($item->fresh()->trashed())->toBeTrue();
});

it('returns 404 for an item on a different list', function () {
    $item = ShoppingListItem::factory()->create();
    $otherList = ShoppingList::factory()->create();

    $this->deleteJson(route('api.v1.shopping-lists.items.destroy', [$otherList, $item]))->assertNotFound();

    expect($item->fresh()->trashed())->toBeFalse();
});

it('proposes what to buy for the approved meals without adding anything', function () {
    $list = ShoppingList::factory()->create();
    Meal::factory()->create(['date' => '2026-10-12', 'name' => 'Chilli']);
    fakeClaudeProcess(claudeStructuredResult(['items' => [
        ['name' => '500g beef mince', 'section' => 'Meat', 'meals' => ['Chilli']],
    ]]));

    $response = $this->postJson(route('api.v1.shopping-lists.proposals.store', $list), ['from' => '2026-10-12', 'to' => '2026-10-18']);

    $response->assertOk()->assertExactJson(['data' => [
        ['name' => '500g beef mince', 'section' => 'Meat', 'meals' => ['Chilli']],
    ]]);
    expect($list->items()->count())->toBe(0);
});
