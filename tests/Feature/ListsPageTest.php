<?php

use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Services\Mcp\McpException;
use App\Services\Skylight\SkylightClient;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Fakes\FakeMcpClient;
use Tests\Fakes\FakeSkylight;

it('redirects guests to the login page', function () {
    $response = $this->get(route('lists'));

    $response->assertRedirect(route('login'));
});

it('shows the default list grouped by section, with ticked-off items apart', function () {
    $groceries = ShoppingList::factory()->create(['name' => 'Groceries', 'is_default' => true]);
    ShoppingList::factory()->create(['name' => 'Costco']);
    ShoppingListItem::factory()->for($groceries)->create(['name' => 'Milk', 'section' => 'Dairy']);
    ShoppingListItem::factory()->for($groceries)->create(['name' => 'Bin bags', 'section' => null]);
    ShoppingListItem::factory()->for($groceries)->completed()->create(['name' => 'Bananas', 'section' => 'Produce']);

    $response = $this->actingAs(User::factory()->create())->get(route('lists'));

    $response->assertOk()
        ->assertSeeInOrder(['Groceries', 'Costco', 'Dairy', 'Milk', 'Other', 'Bin bags', 'Ticked off (1)', 'Bananas']);
});

it('adds an item with a section, recording who added it', function () {
    $user = User::factory()->create();
    $list = ShoppingList::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::lists', ['shoppingList' => $list])
        ->set('name', 'Coffee')
        ->set('section', 'Drinks')
        ->call('addItem')
        ->assertHasNoErrors()
        ->assertSet('name', '');

    $this->assertDatabaseHas('shopping_list_items', [
        'shopping_list_id' => $list->id, 'name' => 'Coffee', 'section' => 'Drinks', 'added_by' => $user->id, 'skylight_id' => null,
    ]);
});

it('requires a name to add an item', function () {
    $list = ShoppingList::factory()->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::lists', ['shoppingList' => $list])
        ->call('addItem')
        ->assertHasErrors(['name' => 'required']);

    expect($list->items()->count())->toBe(0);
});

it('ticks off and unticks an item', function () {
    $item = ShoppingListItem::factory()->create();
    $page = Livewire::actingAs(User::factory()->create())->test('pages::lists', ['shoppingList' => $item->shoppingList]);

    $page->call('toggle', $item->id);
    expect($item->fresh()->isCompleted())->toBeTrue();

    $page->call('toggle', $item->id);
    expect($item->fresh()->isCompleted())->toBeFalse();
});

it('soft-deletes a removed item so the next sync can remove it from skylight', function () {
    $item = ShoppingListItem::factory()->synced()->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::lists', ['shoppingList' => $item->shoppingList])
        ->call('remove', $item->id);

    $this->assertSoftDeleted($item);
});

it('renames an item and changes its section', function () {
    $item = ShoppingListItem::factory()->create(['name' => 'Milk', 'section' => null]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::lists', ['shoppingList' => $item->shoppingList])
        ->call('startEditing', $item->id)
        ->assertSet('editName', 'Milk')
        ->set('editName', 'Oat milk')
        ->set('editSection', 'Dairy')
        ->call('saveEdit')
        ->assertHasNoErrors()
        ->assertSet('editingItemId', null);

    expect($item->fresh())->name->toBe('Oat milk')->section->toBe('Dairy');
});

it('cannot change items on a different list than the one shown', function () {
    $shown = ShoppingList::factory()->create();
    $otherItem = ShoppingListItem::factory()->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::lists', ['shoppingList' => $shown])
        ->call('remove', $otherItem->id)
        ->assertNotFound();

    $this->assertNotSoftDeleted($otherItem);
});

it('syncs with skylight and shows what changed', function () {
    $skylight = (new FakeSkylight)->addList('9002', 'Groceries', isDefault: true);
    $skylight->addItem('9002', 'Milk', section: 'Dairy');
    app()->instance(SkylightClient::class, new SkylightClient($skylight));

    Livewire::actingAs(User::factory()->create())
        ->test('pages::lists')
        ->call('sync')
        ->assertDispatched('toast-show', fn (string $event, array $params) => $params['slots']['text'] === '1 change from Skylight.')
        ->assertSee('Milk');

    expect(ShoppingList::sole()->items()->sole()->name)->toBe('Milk');
});

it('shows the reason when sync fails', function () {
    app()->instance(SkylightClient::class, new SkylightClient(new FakeMcpClient([
        'skylight_list_lists' => fn () => throw new McpException('The [skylight_list_lists] tool failed: Skylight error 401'),
    ])));

    Livewire::actingAs(User::factory()->create())
        ->test('pages::lists')
        ->call('sync')
        ->assertDispatched('toast-show', fn (string $event, array $params) => $params['dataset']['variant'] === 'danger'
            && $params['slots']['text'] === 'The [skylight_list_lists] tool failed: Skylight error 401');
});

it('does not start a second sync while one is running', function () {
    $skylight = (new FakeSkylight)->addList('9002', 'Groceries');
    app()->instance(SkylightClient::class, new SkylightClient($skylight));
    $lock = Cache::lock('skylight-sync', 300);
    $lock->get();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::lists')
        ->call('sync')
        ->assertDispatched('toast-show', fn (string $event, array $params) => $params['dataset']['variant'] === 'warning');

    expect(ShoppingList::count())->toBe(0);
    $lock->release();
});

it('creates a list to be added to skylight at the next sync and opens it', function () {
    $component = Livewire::actingAs(User::factory()->create())
        ->test('pages::lists')
        ->set('newListName', 'Party')
        ->set('newListColor', '#F28B82')
        ->call('createList')
        ->assertHasNoErrors();

    $list = ShoppingList::sole();
    expect($list)->name->toBe('Party')->color->toBe('#F28B82')->skylight_id->toBeNull();
    $component->assertRedirect(route('lists', $list));
});

it('only accepts the offered colors for a new list', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::lists')
        ->set('newListName', 'Party')
        ->set('newListColor', 'red; background: url(x)')
        ->call('createList')
        ->assertHasErrors(['newListColor' => 'in']);

    expect(ShoppingList::count())->toBe(0);
});

it('renames the list', function () {
    $list = ShoppingList::factory()->fromSkylight()->create(['name' => 'Groceries']);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::lists', ['shoppingList' => $list])
        ->assertSet('listName', 'Groceries')
        ->set('listName', 'Weekly shop')
        ->call('renameList')
        ->assertHasNoErrors();

    expect($list->fresh()->name)->toBe('Weekly shop');
});
