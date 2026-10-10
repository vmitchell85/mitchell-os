<?php

use App\Actions\Recipes\ImportRecipe;
use App\Actions\Recipes\RecipeImportException;
use App\Models\Recipe;
use App\Services\Claude\ClaudeException;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Recipe')] class extends Component {
    #[Locked]
    public ?Recipe $recipe = null;

    public string $importUrl = '';

    public string $name = '';

    public string $servings = '';

    public string $totalMinutes = '';

    public string $ingredients = '';

    public string $steps = '';

    public string $notes = '';

    public string $sourceUrl = '';

    public function mount(?Recipe $recipe = null): void
    {
        if ($recipe?->exists) {
            $this->recipe = $recipe;
            $this->name = $recipe->name;
            $this->servings = $recipe->servings ?? '';
            $this->totalMinutes = (string) ($recipe->total_minutes ?? '');
            $this->ingredients = $recipe->ingredients ?? '';
            $this->steps = $recipe->steps ?? '';
            $this->notes = $recipe->notes ?? '';
            $this->sourceUrl = $recipe->source_url ?? '';
        }
    }

    /**
     * Fill the form from a recipe web page, for checking before saving.
     */
    public function import(ImportRecipe $importRecipe): void
    {
        $validated = $this->validate([
            'importUrl' => ['required', 'url:http,https', 'max:2048'],
        ]);

        try {
            $imported = $importRecipe->handle(Auth::user(), $validated['importUrl']);
        } catch (RecipeImportException|ClaudeException $exception) {
            if ($exception instanceof ClaudeException) {
                report($exception);
            }

            $this->addError('importUrl', $exception->getMessage());

            return;
        }

        $this->name = $imported['name'];
        $this->servings = $imported['servings'] ?? '';
        $this->totalMinutes = (string) ($imported['total_minutes'] ?? '');
        $this->ingredients = implode("\n", $imported['ingredients']);
        $this->steps = implode("\n", $imported['steps']);
        $this->notes = $imported['notes'] ?? '';
        $this->sourceUrl = $imported['source_url'];
        $this->reset('importUrl');

        Flux::toast(text: __('Imported. Check it over, then save.'));
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'servings' => ['nullable', 'string', 'max:50'],
            'totalMinutes' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'ingredients' => ['nullable', 'string', 'max:10000'],
            'steps' => ['nullable', 'string', 'max:20000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'sourceUrl' => ['nullable', 'url:http,https', 'max:2048'],
        ]);

        $attributes = [
            'name' => trim($validated['name']),
            'servings' => trim($validated['servings'] ?? '') ?: null,
            'total_minutes' => ((int) ($validated['totalMinutes'] ?? 0)) ?: null,
            'ingredients' => Recipe::joinLines(explode("\n", $validated['ingredients'] ?? '')),
            'steps' => Recipe::joinLines(explode("\n", $validated['steps'] ?? '')),
            'notes' => trim($validated['notes'] ?? '') ?: null,
            'source_url' => $validated['sourceUrl'] ?: null,
        ];

        if ($this->recipe !== null) {
            $this->recipe->update($attributes);
        } else {
            $this->recipe = Recipe::create([...$attributes, 'added_by' => Auth::id()]);
        }

        $this->redirectRoute('recipes.show', $this->recipe, navigate: true);
    }
}; ?>

<div class="mx-auto flex max-w-3xl flex-col gap-6">
    <div class="flex items-center justify-between gap-3">
        <flux:heading size="xl">{{ $recipe ? __('Edit recipe') : __('Add a recipe') }}</flux:heading>

        <flux:button :href="$recipe ? route('recipes.show', $recipe) : route('recipes')" variant="ghost" wire:navigate>{{ __('Cancel') }}</flux:button>
    </div>

    <form wire:submit="import" class="flex flex-col gap-2 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:heading>{{ __('Import from a link') }}</flux:heading>
        <flux:text>{{ __('Paste a recipe page and Claude fills in the form below for you to check.') }}</flux:text>

        <div class="flex items-start gap-2">
            <div class="flex-1">
                <flux:input wire:model="importUrl" type="url" placeholder="https://" />
                <flux:error name="importUrl" />
            </div>

            <flux:button type="submit" wire:loading.attr="disabled" wire:target="import">
                <span wire:loading.remove wire:target="import">{{ __('Import') }}</span>
                <span wire:loading wire:target="import">{{ __('Importing…') }}</span>
            </flux:button>
        </div>
    </form>

    <form wire:submit="save" class="flex flex-col gap-4">
        <flux:input wire:model="name" :label="__('Name')" />

        <div class="grid grid-cols-2 gap-3">
            <flux:input wire:model="servings" :label="__('Servings')" :placeholder="__('e.g. 4')" />
            <flux:input wire:model="totalMinutes" type="number" min="0" :label="__('Total time (minutes)')" />
        </div>

        <flux:textarea wire:model="ingredients" :label="__('Ingredients')" :description="__('One per line.')" rows="8" />
        <flux:textarea wire:model="steps" :label="__('Steps')" :description="__('One per line.')" rows="8" />
        <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" />
        <flux:input wire:model="sourceUrl" type="url" :label="__('Source')" placeholder="https://" />

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary">{{ __('Save recipe') }}</flux:button>
        </div>
    </form>
</div>
