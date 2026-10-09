<div wire:key="item-{{ $item->id }}" class="group flex items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
    @if ($editingItemId === $item->id)
        <form wire:submit="saveEdit" class="flex flex-1 flex-col gap-2 sm:flex-row sm:items-start">
            <div class="flex-1">
                <flux:input wire:model="editName" size="sm" :aria-label="__('Item')" autofocus />
                <flux:error name="editName" />
            </div>

            <flux:input wire:model="editSection" size="sm" list="sections" class="sm:w-40" :placeholder="__('Section')" :aria-label="__('Section')" />

            <div class="flex gap-1">
                <flux:button type="submit" size="sm" variant="primary">{{ __('Save') }}</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="cancelEditing">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @else
        <input
            type="checkbox"
            id="item-{{ $item->id }}"
            class="size-5 shrink-0 cursor-pointer accent-zinc-800 dark:accent-zinc-200"
            wire:click="toggle({{ $item->id }})"
            @checked($item->isCompleted())
        />

        <label for="item-{{ $item->id }}" @class(['flex-1 cursor-pointer', 'text-zinc-400 line-through' => $item->isCompleted()])>
            {{ $item->name }}
        </label>

        <div class="flex opacity-100 sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100">
            <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="startEditing({{ $item->id }})" :aria-label="__('Edit :item', ['item' => $item->name])" />
            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="remove({{ $item->id }})" :aria-label="__('Remove :item', ['item' => $item->name])" />
        </div>
    @endif
</div>
