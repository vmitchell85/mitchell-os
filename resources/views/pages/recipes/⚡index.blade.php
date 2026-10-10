<?php

use App\Models\Recipe;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Recipes')] class extends Component {
    #[Url(except: '')]
    public string $search = '';

    /**
     * @return Collection<int, Recipe>
     */
    #[Computed]
    public function recipes(): Collection
    {
        return Recipe::query()
            ->when(trim($this->search) !== '', fn ($query) => $query->where('name', 'like', '%'.addcslashes(trim($this->search), '%_\\').'%'))
            ->orderBy('name')
            ->get();
    }
}; ?>

<div class="mx-auto flex max-w-3xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="xl">{{ __('Recipes') }}</flux:heading>

        <flux:button :href="route('recipes.create')" variant="primary" icon="plus" wire:navigate>{{ __('Add a recipe') }}</flux:button>
    </div>

    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search recipes')" clearable />

    <div class="flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700">
        @forelse ($this->recipes as $recipe)
            <a wire:key="recipe-{{ $recipe->id }}" href="{{ route('recipes.show', $recipe) }}" wire:navigate class="flex items-center justify-between gap-3 py-3 hover:text-zinc-900 dark:hover:text-white">
                <span class="font-medium">{{ $recipe->name }}</span>

                <flux:text size="sm" class="shrink-0">
                    @if ($recipe->total_minutes)
                        {{ __(':count min', ['count' => $recipe->total_minutes]) }}
                    @endif
                    @if ($recipe->total_minutes && $recipe->servings)
                        ·
                    @endif
                    @if ($recipe->servings)
                        {{ __('Serves :servings', ['servings' => $recipe->servings]) }}
                    @endif
                </flux:text>
            </a>
        @empty
            <div class="flex flex-col items-center gap-2 py-16 text-center">
                <flux:heading>{{ $search !== '' ? __('No recipes match.') : __('No recipes yet') }}</flux:heading>
                @if ($search === '')
                    <flux:text>{{ __('Add one by hand, import it from a link, save a meal from the Meals page, or ask chat to save one.') }}</flux:text>
                @endif
            </div>
        @endforelse
    </div>
</div>
