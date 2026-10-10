<?php

use App\Models\Meal;
use App\Models\Recipe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Recipe')] class extends Component {
    #[Locked]
    public Recipe $recipe;

    public string $planDate = '';

    public string $planCategory = 'Dinner';

    public function mount(Recipe $recipe): void
    {
        $this->recipe = $recipe;
        $this->planDate = CarbonImmutable::today(config('app.display_timezone'))->toDateString();
    }

    /**
     * Add the recipe to the meal plan, approved, with its ingredients.
     */
    public function plan(): void
    {
        $validated = $this->validate([
            'planDate' => ['required', 'date'],
            'planCategory' => ['required', Rule::in(Meal::Categories)],
        ]);

        Meal::create([
            'date' => $validated['planDate'],
            'category' => $validated['planCategory'],
            'name' => $this->recipe->name,
            'description' => $this->recipe->ingredients,
            'recipe_id' => $this->recipe->id,
            'approved_at' => now(),
            'added_by' => Auth::id(),
        ]);

        $this->redirectRoute('meals', ['week' => CarbonImmutable::parse($validated['planDate'])->startOfWeek()->toDateString()], navigate: true);
    }

    /**
     * Meals planned from it keep their own copy of the ingredients.
     */
    public function delete(): void
    {
        $this->recipe->delete();

        $this->redirectRoute('recipes', navigate: true);
    }
}; ?>

<div class="mx-auto flex max-w-3xl flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:button :href="route('recipes')" variant="ghost" size="sm" icon="arrow-left" wire:navigate class="-ms-2 mb-2">{{ __('Recipes') }}</flux:button>
            <flux:heading size="xl">{{ $recipe->name }}</flux:heading>
            <flux:text class="mt-1">
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
        </div>

        <div class="flex gap-2">
            <flux:modal.trigger name="plan-recipe">
                <flux:button variant="primary" icon="calendar">{{ __('Plan it') }}</flux:button>
            </flux:modal.trigger>
            <flux:button :href="route('recipes.edit', $recipe)" icon="pencil-square" wire:navigate>{{ __('Edit') }}</flux:button>
            <flux:button variant="ghost" icon="trash" wire:click="delete" wire:confirm="{{ __('Delete this recipe? Meals already planned from it stay on the plan.') }}" :aria-label="__('Delete')" />
        </div>
    </div>

    @if ($recipe->ingredientList() !== [])
        <section>
            <flux:heading size="lg" class="mb-2">{{ __('Ingredients') }}</flux:heading>
            <ul class="list-disc space-y-1 ps-5">
                @foreach ($recipe->ingredientList() as $index => $ingredient)
                    <li wire:key="ingredient-{{ $index }}">{{ $ingredient }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($recipe->stepList() !== [])
        <section>
            <flux:heading size="lg" class="mb-2">{{ __('Steps') }}</flux:heading>
            <ol class="list-decimal space-y-2 ps-5">
                @foreach ($recipe->stepList() as $index => $step)
                    <li wire:key="step-{{ $index }}">{{ $step }}</li>
                @endforeach
            </ol>
        </section>
    @endif

    @if ($recipe->notes)
        <section>
            <flux:heading size="lg" class="mb-2">{{ __('Notes') }}</flux:heading>
            <flux:text class="whitespace-pre-line">{{ $recipe->notes }}</flux:text>
        </section>
    @endif

    @if ($recipe->source_url)
        <flux:link :href="$recipe->source_url" external>{{ __('Original recipe') }}</flux:link>
    @endif

    <flux:modal name="plan-recipe" class="md:w-96">
        <form wire:submit="plan" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Add to the meal plan') }}</flux:heading>

            <div class="grid grid-cols-2 gap-3">
                <flux:input type="date" wire:model="planDate" :label="__('Date')" />
                <flux:select wire:model="planCategory" :label="__('Meal')">
                    @foreach (Meal::Categories as $category)
                        <flux:select.option :value="$category">{{ $category }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary">{{ __('Add to plan') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
