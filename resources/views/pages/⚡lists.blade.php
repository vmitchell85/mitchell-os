<?php

use App\Actions\Skylight\SyncWithSkylight;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Services\Mcp\McpException;
use Flux\Flux;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Shopping lists')] class extends Component {
    #[Locked]
    public ?ShoppingList $list = null;

    public string $name = '';

    public string $section = '';

    #[Locked]
    public ?int $editingItemId = null;

    public string $editName = '';

    public string $editSection = '';

    /**
     * Mount the component, showing the default list when none is chosen.
     */
    public function mount(?ShoppingList $shoppingList = null): void
    {
        $this->list = $shoppingList?->exists
            ? $shoppingList
            : ShoppingList::orderByDesc('is_default')->orderBy('name')->first();
    }

    public function addItem(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'section' => ['nullable', 'string', 'max:100'],
        ]);

        $this->list->items()->create([
            'name' => $validated['name'],
            'section' => $validated['section'] ?: null,
            'position' => (int) $this->list->items()->max('position') + 1,
            'added_by' => Auth::id(),
        ]);

        $this->reset('name');
    }

    public function toggle(int $itemId): void
    {
        $item = $this->findItem($itemId);

        $item->update(['completed_at' => $item->isCompleted() ? null : now()]);
    }

    /**
     * Soft-delete, so the next sync can remove it from Skylight too.
     */
    public function remove(int $itemId): void
    {
        $this->findItem($itemId)->delete();
    }

    public function startEditing(int $itemId): void
    {
        $item = $this->findItem($itemId);

        $this->editingItemId = $item->id;
        $this->editName = $item->name;
        $this->editSection = $item->section ?? '';
        $this->resetValidation();
    }

    public function saveEdit(): void
    {
        $validated = $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editSection' => ['nullable', 'string', 'max:100'],
        ]);

        $this->findItem($this->editingItemId)->update([
            'name' => $validated['editName'],
            'section' => $validated['editSection'] ?: null,
        ]);

        $this->cancelEditing();
    }

    public function cancelEditing(): void
    {
        $this->reset('editingItemId', 'editName', 'editSection');
    }

    public function sync(SyncWithSkylight $sync): void
    {
        try {
            $report = $sync->handle();
        } catch (LockTimeoutException) {
            Flux::toast(variant: 'warning', text: __('A sync is already running. Try again in a moment.'));

            return;
        } catch (McpException $exception) {
            report($exception);
            Flux::toast(variant: 'danger', heading: __('Sync failed'), text: $exception->getMessage());

            return;
        }

        $this->list ??= ShoppingList::orderByDesc('is_default')->orderBy('name')->first();
        $this->list?->refresh();

        Flux::toast(variant: 'success', heading: __('Synced with Skylight'), text: $report->summary());
    }

    /**
     * @return Collection<int, ShoppingList>
     */
    #[Computed]
    public function lists(): Collection
    {
        return ShoppingList::orderByDesc('is_default')->orderBy('name')->get();
    }

    /**
     * Items still to buy, grouped by section with unsectioned items last.
     *
     * @return \Illuminate\Support\Collection<string, Collection<int, ShoppingListItem>>
     */
    #[Computed]
    public function pendingBySection(): \Illuminate\Support\Collection
    {
        return $this->list?->items()->whereNull('completed_at')->orderBy('position')->get()
            ->groupBy(fn (ShoppingListItem $item): string => $item->section ?? '')
            ->sortKeysUsing(fn (string $a, string $b): int => [$a === '', $a] <=> [$b === '', $b])
            ?? collect();
    }

    /**
     * @return Collection<int, ShoppingListItem>
     */
    #[Computed]
    public function completedItems(): Collection
    {
        return $this->list?->items()->whereNotNull('completed_at')->latest('completed_at')->get() ?? new Collection;
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function sections(): array
    {
        return $this->list?->items()->whereNotNull('section')->distinct()->orderBy('section')->pluck('section')->all() ?? [];
    }

    protected function findItem(?int $itemId): ShoppingListItem
    {
        return $this->list->items()->findOrFail($itemId);
    }
}; ?>

<div class="mx-auto flex max-w-3xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-2">
            @foreach ($this->lists as $item)
                <flux:button
                    wire:key="list-{{ $item->id }}"
                    :href="route('lists', $item)"
                    size="sm"
                    :variant="$item->is($list) ? 'primary' : 'filled'"
                    wire:navigate
                >
                    {{ $item->name }}
                </flux:button>
            @endforeach
        </div>

        <div class="flex items-center gap-3">
            @if ($list?->last_synced_at)
                <flux:text size="sm">{{ __('Synced :time', ['time' => $list->last_synced_at->diffForHumans()]) }}</flux:text>
            @endif

            <flux:button size="sm" icon="arrow-path" wire:click="sync" wire:loading.attr="disabled" wire:target="sync">
                <span wire:loading.remove wire:target="sync">{{ __('Sync with Skylight') }}</span>
                <span wire:loading wire:target="sync">{{ __('Syncing…') }}</span>
            </flux:button>
        </div>
    </div>

    @if ($list === null)
        <div class="flex flex-col items-center gap-2 py-24 text-center">
            <flux:heading size="xl">{{ __('No shopping lists yet') }}</flux:heading>
            <flux:text>{{ __('Sync with Skylight to bring in your shopping lists.') }}</flux:text>
        </div>
    @else
        <form wire:submit="addItem" class="flex flex-col gap-2 sm:flex-row sm:items-start">
            <div class="flex-1">
                <flux:input wire:model="name" :placeholder="__('Add an item…')" :aria-label="__('Item')" />
                <flux:error name="name" />
            </div>

            <div class="sm:w-48">
                <flux:input wire:model="section" list="sections" :placeholder="__('Section (optional)')" :aria-label="__('Section')" />
                <datalist id="sections">
                    @foreach ($this->sections as $sectionName)
                        <option value="{{ $sectionName }}"></option>
                    @endforeach
                </datalist>
            </div>

            <flux:button type="submit" variant="primary" icon="plus">{{ __('Add') }}</flux:button>
        </form>

        @forelse ($this->pendingBySection as $sectionName => $items)
            <section wire:key="section-{{ $sectionName ?: 'none' }}" class="flex flex-col gap-1">
                <flux:heading size="sm" class="text-zinc-500 uppercase">{{ $sectionName ?: __('Other') }}</flux:heading>

                @foreach ($items as $item)
                    @include('partials.shopping-list-item', ['item' => $item])
                @endforeach
            </section>
        @empty
            <flux:text class="py-8 text-center">{{ __('Nothing left to buy.') }}</flux:text>
        @endforelse

        @if ($this->completedItems->isNotEmpty())
            <details class="group">
                <summary class="cursor-pointer text-sm text-zinc-500">
                    {{ __('Ticked off (:count)', ['count' => $this->completedItems->count()]) }}
                </summary>

                <div class="mt-2 flex flex-col gap-1">
                    @foreach ($this->completedItems as $item)
                        @include('partials.shopping-list-item', ['item' => $item])
                    @endforeach
                </div>
            </details>
        @endif
    @endif
</div>
