<?php

namespace App\NativeComponents\Recipes;

use App\Services\Api\ApiClient;
use App\Services\Api\Session;
use App\Services\Api\UnauthenticatedException;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class RecipeIndex extends NativeComponent
{
    public string $search = '';

    public string $importUrl = '';

    public bool $importing = false;

    public ?string $error = null;

    /** @var list<array{id: int, name: string, servings: string|null, total_minutes: int|null}> */
    public array $recipes = [];

    public function navTitle(): string
    {
        return 'Recipes';
    }

    public function mount(): void
    {
        $this->loadRecipes();
    }

    public function onResume(): void
    {
        $this->loadRecipes();
    }

    public function updatedSearch(): void
    {
        $this->loadRecipes();
    }

    public function loadRecipes(): void
    {
        try {
            $response = app(ApiClient::class)->get('/recipes', array_filter(['search' => trim($this->search)]));
        } catch (UnauthenticatedException) {
            $this->signOut();

            return;
        }

        if (! $response->successful()) {
            $this->error = 'Couldn\'t load recipes. Pull down to try again.';

            return;
        }

        $this->error = null;
        $this->recipes = $response->json('data') ?? [];
    }

    public function openRecipe(int $id): void
    {
        $this->navigate("/recipes/{$id}");
    }

    public function addRecipe(): void
    {
        $this->navigate('/recipes/new');
    }

    public function importRecipe(): void
    {
        $url = trim($this->importUrl);

        if ($url === '') {
            $this->error = 'Paste a link to a recipe first.';

            return;
        }

        $this->importing = true;
        $this->error = null;

        try {
            $response = app(ApiClient::class)->post('/recipes/import', ['url' => $url]);
        } catch (UnauthenticatedException) {
            $this->signOut();

            return;
        } finally {
            $this->importing = false;
        }

        if (! $response->successful()) {
            $this->error = $response->json('errors.url.0') ?? $response->json('message') ?? 'Couldn\'t import that recipe.';

            return;
        }

        $this->importUrl = '';
        $this->navigate('/recipes/new', ['draft' => $response->json('data')]);
    }

    protected function signOut(): void
    {
        app(Session::class)->signOut();
        $this->replace('/sign-in');
    }

    public function render(): View
    {
        return view('native.recipes.recipe-index');
    }
}
