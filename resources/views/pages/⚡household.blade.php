<?php

use App\Models\HouseholdNote;
use App\Models\HouseholdSetting;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Household')] class extends Component {
    public string $foodRules = '';

    public string $note = '';

    #[Locked]
    public ?int $editingNoteId = null;

    public string $editNote = '';

    public function mount(): void
    {
        $this->foodRules = HouseholdSetting::get(HouseholdSetting::FoodRules) ?? '';
    }

    public function saveFoodRules(): void
    {
        $validated = $this->validate([
            'foodRules' => ['nullable', 'string', 'max:2000'],
        ]);

        HouseholdSetting::put(HouseholdSetting::FoodRules, trim($validated['foodRules'] ?? '') ?: null);

        Flux::toast(variant: 'success', text: __('Food rules saved.'));
    }

    public function addNote(): void
    {
        $validated = $this->validate([
            'note' => ['required', 'string', 'max:500'],
        ]);

        HouseholdNote::create(['body' => trim($validated['note']), 'added_by' => Auth::id()]);

        $this->reset('note');
    }

    public function startEditing(int $noteId): void
    {
        $note = HouseholdNote::findOrFail($noteId);

        $this->editingNoteId = $note->id;
        $this->editNote = $note->body;
        $this->resetValidation();
    }

    public function saveNote(): void
    {
        $validated = $this->validate([
            'editNote' => ['required', 'string', 'max:500'],
        ]);

        HouseholdNote::findOrFail($this->editingNoteId)->update(['body' => trim($validated['editNote'])]);

        $this->cancelEditing();
    }

    public function cancelEditing(): void
    {
        $this->reset('editingNoteId', 'editNote');
    }

    public function removeNote(int $noteId): void
    {
        HouseholdNote::findOrFail($noteId)->delete();
    }

    /**
     * @return Collection<int, HouseholdNote>
     */
    #[Computed]
    public function notes(): Collection
    {
        return HouseholdNote::with('addedBy:id,name')->oldest('id')->get();
    }
}; ?>

<div class="mx-auto flex max-w-3xl flex-col gap-10">
    <section class="flex flex-col gap-4">
        <div>
            <flux:heading size="xl">{{ __('Household notes') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Things Claude should always know about your family: ages, schools, allergies, routines and preferences. Every chat, meal plan and shopping list uses them. You can also tell chat "remember that…".') }}</flux:text>
        </div>

        <form wire:submit="addNote" class="flex items-start gap-2">
            <div class="flex-1">
                <flux:input wire:model="note" :placeholder="__('e.g. We have soccer practice on Tuesdays until 6:30 PM.')" />
                <flux:error name="note" />
            </div>

            <flux:button type="submit" variant="primary" icon="plus">{{ __('Add') }}</flux:button>
        </form>

        <div class="flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($this->notes as $item)
                <div wire:key="note-{{ $item->id }}" class="flex items-start gap-3 py-3">
                    @if ($editingNoteId === $item->id)
                        <form wire:submit="saveNote" class="flex flex-1 items-start gap-2">
                            <div class="flex-1">
                                <flux:input wire:model="editNote" autofocus />
                                <flux:error name="editNote" />
                            </div>

                            <flux:button type="submit" size="sm" variant="primary">{{ __('Save') }}</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="cancelEditing">{{ __('Cancel') }}</flux:button>
                        </form>
                    @else
                        <div class="min-w-0 flex-1">
                            <flux:text class="text-zinc-900 dark:text-white">{{ $item->body }}</flux:text>
                            <flux:text size="sm">
                                {{ $item->addedBy ? __('Added by :name', ['name' => $item->addedBy->name]) : __('Added') }}
                                · {{ $item->created_at?->timezone(config('app.display_timezone'))->format('M j, Y') }}
                            </flux:text>
                        </div>

                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="startEditing({{ $item->id }})" :aria-label="__('Edit')" />
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeNote({{ $item->id }})" :aria-label="__('Remove')" />
                    @endif
                </div>
            @empty
                <flux:text class="py-3">{{ __('No notes yet.') }}</flux:text>
            @endforelse
        </div>
    </section>

    <section class="flex flex-col gap-4">
        <div>
            <flux:heading size="xl">{{ __('Food rules') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Strict rules every meal plan, shopping list and chat must follow.') }}</flux:text>
        </div>

        <form wire:submit="saveFoodRules" class="flex flex-col gap-3">
            <flux:textarea wire:model="foodRules" rows="3" :placeholder="__('e.g. No seafood. One of the kids is allergic to nuts.')" />
            <flux:error name="foodRules" />

            <div>
                <flux:button type="submit" variant="primary">{{ __('Save food rules') }}</flux:button>
            </div>
        </form>
    </section>
</div>
