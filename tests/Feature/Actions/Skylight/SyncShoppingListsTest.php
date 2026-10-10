<?php

use App\Actions\Skylight\SyncReport;
use App\Actions\Skylight\SyncShoppingLists;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Services\Skylight\SkylightClient;
use Tests\Fakes\FakeSkylight;

function syncShoppingLists(FakeSkylight $skylight): SyncReport
{
    return (new SyncShoppingLists(new SkylightClient($skylight)))->handle();
}

/**
 * A frame with one grocery list holding milk (Dairy) and ticked-off bananas (Produce), already synced once.
 *
 * @return array{0: FakeSkylight, 1: ShoppingList}
 */
function syncedGroceries(): array
{
    $skylight = (new FakeSkylight)->addList('9002', 'Groceries', isDefault: true);
    $skylight->addItem('9002', 'Milk', section: 'Dairy', id: '7001');
    $skylight->addItem('9002', 'Bananas', completed: true, section: 'Produce', id: '7002');
    syncShoppingLists($skylight);
    $skylight->writes = [];

    return [$skylight, ShoppingList::where('skylight_id', '9002')->sole()];
}

function localItem(ShoppingList $list, string $skylightId): ShoppingListItem
{
    return $list->items()->withTrashed()->where('skylight_id', $skylightId)->sole();
}

it('imports shopping lists and their items on the first sync, ignoring other lists', function () {
    $skylight = (new FakeSkylight)
        ->addList('9001', 'To do', kind: 'other')
        ->addList('9002', 'Groceries', isDefault: true);
    $skylight->addItem('9001', 'Fix the gate');
    $skylight->addItem('9002', 'Milk', section: 'Dairy');
    $skylight->addItem('9002', 'Bananas', completed: true, section: 'Produce');

    $report = syncShoppingLists($skylight);

    $list = ShoppingList::sole();
    expect($list)->name->toBe('Groceries')->is_default->toBeTrue()->last_synced_at->not->toBeNull();
    expect($list->items()->orderBy('position')->get()->map(fn ($item) => [$item->name, $item->section, $item->isCompleted()])->all())
        ->toBe([['Milk', 'Dairy', false], ['Bananas', 'Produce', true]]);
    expect($report->pulled)->toBe(2);
    expect($skylight->writes)->toBe([]);
});

it('makes no changes when nothing changed since the last sync', function () {
    [$skylight] = syncedGroceries();

    $report = syncShoppingLists($skylight);

    expect($report->hasChanges())->toBeFalse();
    expect($skylight->writes)->toBe([]);
});

it('sends items added here to skylight with their section and tick', function () {
    [$skylight, $list] = syncedGroceries();
    $item = ShoppingListItem::factory()->for($list)->completed()->create(['name' => 'Coffee', 'section' => 'Drinks']);

    $report = syncShoppingLists($skylight);

    $item->refresh();
    expect($skylight->item('9002', $item->skylight_id))
        ->toMatchArray(['label' => 'Coffee', 'section' => 'Drinks', 'status' => 'completed']);
    expect($report->pushed)->toBe(1);
});

it('never sends an item that was added and deleted here between syncs', function () {
    [$skylight, $list] = syncedGroceries();
    $item = ShoppingListItem::factory()->for($list)->create();
    $item->delete();

    syncShoppingLists($skylight);

    expect($skylight->writes)->toBe([]);
    $this->assertModelMissing($item);
});

it('pulls items added, ticked, renamed and moved in skylight', function () {
    [$skylight, $list] = syncedGroceries();
    $skylight->addItem('9002', 'Eggs', section: 'Dairy');
    $skylight->items['9002']['7001'] = [...$skylight->items['9002']['7001'], 'status' => 'completed', 'label' => 'Oat milk', 'section' => 'Plant milk'];

    $report = syncShoppingLists($skylight);

    expect(localItem($list, '7001')->syncableState())->toBe(['name' => 'Oat milk', 'section' => 'Plant milk', 'completed' => true]);
    expect($list->items()->where('name', 'Eggs')->exists())->toBeTrue();
    expect($report->pulled)->toBe(2);
    expect($skylight->writes)->toBe([]);
});

it('sends ticks, renames and section changes made here', function () {
    [$skylight, $list] = syncedGroceries();
    localItem($list, '7001')->update(['name' => '2% milk', 'section' => 'Fridge', 'completed_at' => now()]);

    syncShoppingLists($skylight);

    expect($skylight->item('9002', '7001'))->toMatchArray(['label' => '2% milk', 'section' => 'Fridge', 'status' => 'completed']);
    expect($skylight->writtenTools())->toBe(['skylight_update_list_item']);
});

it('lets skylight win a tick that changed on both sides, but keeps the name changed here', function () {
    [$skylight, $list] = syncedGroceries();
    localItem($list, '7002')->update(['completed_at' => null, 'name' => 'Ripe bananas']);
    $skylight->items['9002']['7002'] = [...$skylight->items['9002']['7002'], 'status' => 'pending', 'label' => 'Plantains'];
    localItem($list, '7001')->update(['completed_at' => now()]);
    $skylight->items['9002']['7001']['status'] = 'pending';

    syncShoppingLists($skylight);

    expect(localItem($list, '7002')->syncableState())->toBe(['name' => 'Ripe bananas', 'section' => 'Produce', 'completed' => false]);
    expect($skylight->item('9002', '7002'))->toMatchArray(['label' => 'Ripe bananas', 'status' => 'pending']);
    expect(localItem($list, '7001')->isCompleted())->toBeTrue();
    expect($skylight->item('9002', '7001')['status'])->toBe('completed');
});

it('combines different fields changed on each side', function () {
    [$skylight, $list] = syncedGroceries();
    localItem($list, '7001')->update(['name' => 'Whole milk']);
    $skylight->items['9002']['7001']['status'] = 'completed';

    syncShoppingLists($skylight);

    expect(localItem($list, '7001')->syncableState())->toBe(['name' => 'Whole milk', 'section' => 'Dairy', 'completed' => true]);
    expect($skylight->item('9002', '7001'))->toMatchArray(['label' => 'Whole milk', 'status' => 'completed']);
});

it('removes items here that were deleted in skylight', function () {
    [$skylight, $list] = syncedGroceries();
    unset($skylight->items['9002']['7001']);

    $report = syncShoppingLists($skylight);

    expect($list->items()->withTrashed()->where('skylight_id', '7001')->exists())->toBeFalse();
    expect($report->removedHere)->toBe(1);
});

it('removes items from skylight that were deleted here', function () {
    [$skylight, $list] = syncedGroceries();
    localItem($list, '7001')->delete();

    $report = syncShoppingLists($skylight);

    expect($skylight->item('9002', '7001'))->toBeNull();
    expect($list->items()->withTrashed()->where('skylight_id', '7001')->exists())->toBeFalse();
    expect($report->removedFromSkylight)->toBe(1);
});

it('keeps an item edited here that was deleted in skylight, adding it back', function () {
    [$skylight, $list] = syncedGroceries();
    localItem($list, '7001')->update(['name' => 'Milk x2']);
    unset($skylight->items['9002']['7001']);

    $report = syncShoppingLists($skylight);

    $item = $list->items()->where('name', 'Milk x2')->sole();
    expect($skylight->item('9002', $item->skylight_id))->toMatchArray(['label' => 'Milk x2', 'section' => 'Dairy']);
    expect($report->kept)->toBe(1);
});

it('keeps an item edited in skylight that was deleted here, restoring it', function () {
    [$skylight, $list] = syncedGroceries();
    localItem($list, '7001')->delete();
    $skylight->items['9002']['7001']['label'] = 'Milk x2';

    $report = syncShoppingLists($skylight);

    expect(localItem($list, '7001'))->trashed()->toBeFalse()->name->toBe('Milk x2');
    expect($skylight->item('9002', '7001'))->not->toBeNull();
    expect($report->kept)->toBe(1);
});

it('keeps a list removed from skylight, with its items, and does not re-create it', function () {
    [$skylight, $list] = syncedGroceries();
    unset($skylight->lists['9002']);

    syncShoppingLists($skylight);
    syncShoppingLists($skylight);

    expect($list->fresh())->skylight_id->toBeNull()->removed_from_skylight_at->not->toBeNull();
    expect($list->items()->count())->toBe(2);
    expect($skylight->writtenTools())->not->toContain('skylight_create_list');
});

it('creates lists made here in skylight, along with their items', function () {
    [$skylight] = syncedGroceries();
    $party = ShoppingList::factory()->create(['name' => 'Party', 'color' => '#F28B82']);
    ShoppingListItem::factory()->for($party)->create(['name' => 'Balloons', 'section' => 'Decorations']);

    $report = syncShoppingLists($skylight);

    $party->refresh();
    expect($skylight->lists[$party->skylight_id])->toMatchArray(['label' => 'Party', 'color' => '#F28B82', 'kind' => 'shopping']);
    expect(array_values($skylight->items[$party->skylight_id]))->sequence(
        fn ($item) => $item->toMatchArray(['label' => 'Balloons', 'section' => 'Decorations']),
    );
    expect($report->pushed)->toBe(2);
});

it('renames a list in skylight when it was renamed here', function () {
    [$skylight, $list] = syncedGroceries();
    $list->update(['name' => 'Weekly shop']);
    $skylight->lists['9002']['label'] = 'Food';

    syncShoppingLists($skylight);

    expect($skylight->lists['9002']['label'])->toBe('Weekly shop');
    expect($list->fresh()->name)->toBe('Weekly shop');
});

it('takes a list name changed in skylight when it was not renamed here', function () {
    [$skylight, $list] = syncedGroceries();
    $skylight->lists['9002']['label'] = 'Food';

    syncShoppingLists($skylight);

    expect($list->fresh()->name)->toBe('Food');
    expect($skylight->writes)->toBe([]);
});
