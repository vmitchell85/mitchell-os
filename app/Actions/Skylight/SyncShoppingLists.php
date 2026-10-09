<?php

namespace App\Actions\Skylight;

use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Services\Mcp\McpException;
use App\Services\Skylight\SkylightClient;
use App\Services\Skylight\SkylightList;
use App\Services\Skylight\SkylightListItem;
use Illuminate\Support\Collection;

/**
 * Two-way sync of every Skylight shopping list with the local copies, and
 * of lists made here, which are created in Skylight.
 *
 * Skylight doesn't say when an item last changed, so each item keeps a
 * snapshot of the state both sides agreed on at the last sync. Comparing
 * each side with it shows who changed what. When both sides changed the
 * same field, Skylight wins ticks (people tick things off on the frame)
 * and this app wins names and sections. An item deleted on one side but
 * edited on the other is kept.
 */
class SyncShoppingLists
{
    public function __construct(protected SkylightClient $skylight) {}

    /**
     * @throws McpException
     */
    public function handle(?SyncReport $report = null): SyncReport
    {
        $report ??= new SyncReport;

        /** @var Collection<string, SkylightList> $remoteLists */
        $remoteLists = collect($this->skylight->shoppingLists())->keyBy('id');

        foreach (ShoppingList::whereNotNull('skylight_id')->get() as $list) {
            $remoteList = $remoteLists->pull($list->skylight_id);

            if ($remoteList === null) {
                $list->update(['skylight_id' => null, 'removed_from_skylight_at' => now()]);

                continue;
            }

            $this->syncList($list, $remoteList, $report);
        }

        foreach ($remoteLists as $remoteList) {
            $list = new ShoppingList(['name' => $remoteList->label, 'skylight_snapshot' => ['name' => $remoteList->label]]);
            $this->syncList($list, $remoteList, $report);
        }

        $listsMadeHere = ShoppingList::whereNull('skylight_id')->whereNull('removed_from_skylight_at')->get();

        foreach ($listsMadeHere as $list) {
            $remoteList = $this->skylight->createShoppingList($list->name, $list->color ?? ShoppingList::DefaultColor);
            $list->skylight_snapshot = ['name' => $remoteList->label];
            $report->pushed++;

            $this->syncList($list, $remoteList, $report);
        }

        return $report;
    }

    /**
     * Sync a list's name (this app wins, like item names), then its items.
     */
    protected function syncList(ShoppingList $list, SkylightList $remoteList, SyncReport $report): void
    {
        $agreedName = $list->skylight_snapshot['name'] ?? $remoteList->label;

        if ($list->name !== $agreedName && $list->name !== $remoteList->label) {
            $remoteList = $this->skylight->renameList($remoteList->id, $list->name);
            $report->pushed++;
        } elseif ($list->name === $agreedName && $remoteList->label !== $agreedName) {
            $list->name = $remoteList->label;
            $report->pulled++;
        }

        $list->fill([
            'skylight_id' => $remoteList->id,
            'skylight_snapshot' => ['name' => $list->name],
            'color' => $remoteList->color,
            'is_default' => $remoteList->isDefaultGroceryList,
        ])->save();

        $this->syncItems($list, $report);

        $list->update(['last_synced_at' => now()]);
    }

    protected function syncItems(ShoppingList $list, SyncReport $report): void
    {
        /** @var Collection<string, SkylightListItem> $remoteItems */
        $remoteItems = collect($this->skylight->listItems($list->skylight_id))->keyBy('id');

        foreach ($list->items()->withTrashed()->get() as $item) {
            $remoteItem = $item->skylight_id !== null ? $remoteItems->pull($item->skylight_id) : null;

            match (true) {
                $item->skylight_id === null => $this->pushNewItem($list, $item, $report),
                $remoteItem === null => $this->resolveDeletedInSkylight($list, $item, $report),
                $item->trashed() => $this->resolveDeletedHere($list, $item, $remoteItem, $report),
                default => $this->merge($list, $item, $remoteItem, $report),
            };
        }

        foreach ($remoteItems as $remoteItem) {
            $item = $list->items()->make();
            $this->applyState($item, $this->remoteState($remoteItem), $remoteItem);
            $item->save();
            $report->pulled++;
        }
    }

    protected function pushNewItem(ShoppingList $list, ShoppingListItem $item, SyncReport $report): void
    {
        if ($item->trashed()) {
            $item->forceDelete();

            return;
        }

        $this->createInSkylight($list, $item);
        $report->pushed++;
    }

    protected function createInSkylight(ShoppingList $list, ShoppingListItem $item): void
    {
        $remoteItem = $this->skylight->addListItem($list->skylight_id, $item->name, $item->section);

        if ($item->isCompleted()) {
            $remoteItem = $this->skylight->updateListItem($list->skylight_id, $remoteItem->id, ['isCompleted' => true]);
        }

        $this->applyState($item, $item->syncableState(), $remoteItem);
        $item->save();
    }

    protected function resolveDeletedInSkylight(ShoppingList $list, ShoppingListItem $item, SyncReport $report): void
    {
        if ($item->trashed()) {
            $item->forceDelete();

            return;
        }

        if (! $this->isSameState($item->syncableState(), $item->skylight_snapshot)) {
            $this->createInSkylight($list, $item);
            $report->kept++;

            return;
        }

        $item->forceDelete();
        $report->removedHere++;
    }

    protected function resolveDeletedHere(ShoppingList $list, ShoppingListItem $item, SkylightListItem $remoteItem, SyncReport $report): void
    {
        if (! $this->isSameState($this->remoteState($remoteItem), $item->skylight_snapshot)) {
            $item->restore();
            $this->merge($list, $item, $remoteItem, $report);
            $report->kept++;

            return;
        }

        $this->skylight->deleteListItem($list->skylight_id, $remoteItem->id);
        $item->forceDelete();
        $report->removedFromSkylight++;
    }

    protected function merge(ShoppingList $list, ShoppingListItem $item, SkylightListItem $remoteItem, SyncReport $report): void
    {
        $remote = $this->remoteState($remoteItem);
        $snapshot = $item->skylight_snapshot ?? $remote;
        $merged = $item->syncableState();
        $changesForSkylight = [];

        if ($remote['completed'] !== $snapshot['completed']) {
            $merged['completed'] = $remote['completed'];
        } elseif ($merged['completed'] !== $remote['completed']) {
            $changesForSkylight['isCompleted'] = $merged['completed'];
        }

        foreach (['name' => 'label', 'section' => 'section'] as $field => $skylightField) {
            if ($merged[$field] !== $snapshot[$field]) {
                if ($merged[$field] !== $remote[$field]) {
                    $changesForSkylight[$skylightField] = $merged[$field];
                }
            } elseif ($remote[$field] !== $snapshot[$field]) {
                $merged[$field] = $remote[$field];
            }
        }

        if ($changesForSkylight !== []) {
            $remoteItem = $this->skylight->updateListItem($list->skylight_id, $remoteItem->id, $changesForSkylight);
            $report->pushed++;
        }

        if (! $this->isSameState($merged, $item->syncableState())) {
            $report->pulled++;
        }

        $this->applyState($item, $merged, $remoteItem);
        $item->save();
    }

    /**
     * @param  array{name: string, section: string|null, completed: bool}  $state
     */
    protected function applyState(ShoppingListItem $item, array $state, SkylightListItem $remoteItem): void
    {
        $item->fill([
            'name' => $state['name'],
            'section' => $state['section'],
            'completed_at' => $state['completed'] ? ($item->completed_at ?? now()) : null,
            'skylight_id' => $remoteItem->id,
            'position' => $remoteItem->position,
            'skylight_snapshot' => $state,
        ]);
    }

    /**
     * @return array{name: string, section: string|null, completed: bool}
     */
    protected function remoteState(SkylightListItem $remoteItem): array
    {
        return ['name' => $remoteItem->label, 'section' => $remoteItem->section, 'completed' => $remoteItem->isCompleted];
    }

    /**
     * @param  array{name: string, section: string|null, completed: bool}  $state
     * @param  array{name: string, section: string|null, completed: bool}|null  $other
     */
    protected function isSameState(array $state, ?array $other): bool
    {
        return $other !== null
            && $state['name'] === $other['name']
            && $state['section'] === $other['section']
            && $state['completed'] === $other['completed'];
    }
}
