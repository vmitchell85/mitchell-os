<?php

namespace App\NativeComponents\Recipes;

use App\Services\Api\ApiClient;
use App\Services\Api\Session;
use App\Services\Api\UnauthenticatedException;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Adds a recipe, or edits one when the route has an `id`. An import from a
 * link arrives as the `draft` navigation data to check before saving.
 */
class RecipeForm extends NativeComponent
{
    public string $name = '';

    public string $ingredients = '';

    public string $steps = '';

    public string $servings = '';

    public string $totalMinutes = '';

    public string $notes = '';

    public string $sourceUrl = '';

    public bool $saving = false;

    public ?string $error = null;

    public function navTitle(): string
    {
        return $this->param('id') ? 'Edit recipe' : 'New recipe';
    }

    public function mount(): void
    {
        if ($this->param('id')) {
            $this->loadExisting();

            return;
        }

        $this->fill($this->data('draft', []));
    }

    public function save(): void
    {
        $this->saving = true;
        $this->error = null;

        try {
            $api = app(ApiClient::class);
            $id = $this->param('id');
            $response = $id ? $api->put("/recipes/{$id}", $this->payload()) : $api->post('/recipes', $this->payload());
        } catch (UnauthenticatedException) {
            $this->signOut();

            return;
        } finally {
            $this->saving = false;
        }

        if (! $response->successful()) {
            $this->error = collect($response->json('errors') ?? [])->flatten()->first() ?? $response->json('message') ?? 'Couldn\'t save this recipe.';

            return;
        }

        $id ? $this->back() : $this->replace('/recipes/'.$response->json('data.id'));
    }

    protected function loadExisting(): void
    {
        try {
            $response = app(ApiClient::class)->get('/recipes/'.$this->param('id'));
        } catch (UnauthenticatedException) {
            $this->signOut();

            return;
        }

        if (! $response->successful()) {
            $this->error = 'Couldn\'t load this recipe.';

            return;
        }

        $this->fill($response->json('data'));
    }

    /**
     * @param  array<string, mixed>  $recipe
     */
    protected function fill(array $recipe): void
    {
        $this->name = (string) ($recipe['name'] ?? '');
        $this->ingredients = implode("\n", $recipe['ingredients'] ?? []);
        $this->steps = implode("\n", $recipe['steps'] ?? []);
        $this->servings = (string) ($recipe['servings'] ?? '');
        $this->totalMinutes = (string) ($recipe['total_minutes'] ?? '');
        $this->notes = (string) ($recipe['notes'] ?? '');
        $this->sourceUrl = (string) ($recipe['source_url'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'name' => trim($this->name),
            'ingredients' => $this->lines($this->ingredients),
            'steps' => $this->lines($this->steps),
            'servings' => trim($this->servings) ?: null,
            'total_minutes' => (int) $this->totalMinutes ?: null,
            'notes' => trim($this->notes) ?: null,
            'source_url' => trim($this->sourceUrl) ?: null,
        ];
    }

    /**
     * @return list<string>
     */
    protected function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $text)), fn (string $line): bool => $line !== ''));
    }

    protected function signOut(): void
    {
        app(Session::class)->signOut();
        $this->replace('/sign-in');
    }

    public function render(): View
    {
        return view('native.recipes.recipe-form');
    }
}
