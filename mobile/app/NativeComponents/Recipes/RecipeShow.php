<?php

namespace App\NativeComponents\Recipes;

use App\Services\Api\ApiClient;
use App\Services\Api\Session;
use App\Services\Api\UnauthenticatedException;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Dialog;
use Native\Mobile\Facades\Share;

class RecipeShow extends NativeComponent
{
    /** @var array{id: int, name: string, ingredients: list<string>, steps: list<string>, servings: string|null, total_minutes: int|null, notes: string|null, source_url: string|null}|null */
    public ?array $recipe = null;

    public ?string $error = null;

    public function navTitle(): string
    {
        return $this->recipe['name'] ?? 'Recipe';
    }

    public function mount(): void
    {
        $this->loadRecipe();
    }

    public function onResume(): void
    {
        $this->loadRecipe();
    }

    public function loadRecipe(): void
    {
        try {
            $response = app(ApiClient::class)->get('/recipes/'.$this->param('id'));
        } catch (UnauthenticatedException) {
            $this->signOut();

            return;
        }

        if (! $response->successful()) {
            $this->error = $response->status() === 404 ? 'This recipe no longer exists.' : 'Couldn\'t load this recipe.';

            return;
        }

        $this->error = null;
        $this->recipe = $response->json('data');
    }

    public function edit(): void
    {
        $this->navigate("/recipes/{$this->recipe['id']}/edit");
    }

    public function share(): void
    {
        if ($this->recipe === null) {
            return;
        }

        Share::url($this->recipe['name'], $this->shareText(), $this->recipe['source_url'] ?? '');
    }

    public function confirmDelete(): void
    {
        Dialog::alert('Delete recipe?', 'Meals already planned from it keep their ingredients.', [
            ['label' => 'Cancel', 'style' => 'cancel'],
            ['label' => 'Delete', 'style' => 'destructive'],
        ])->buttonPressed(function ($event): void {
            if ($event->label === 'Delete') {
                $this->deleteRecipe();
            }
        });
    }

    public function deleteRecipe(): void
    {
        try {
            $response = app(ApiClient::class)->delete("/recipes/{$this->recipe['id']}");
        } catch (UnauthenticatedException) {
            $this->signOut();

            return;
        }

        if (! $response->successful()) {
            $this->error = 'Couldn\'t delete this recipe.';

            return;
        }

        $this->back();
    }

    protected function shareText(): string
    {
        $ingredients = array_map(fn (string $line): string => "- {$line}", $this->recipe['ingredients']);
        $steps = array_map(fn (int $number, string $step): string => ($number + 1).". {$step}", array_keys($this->recipe['steps']), $this->recipe['steps']);

        return implode("\n", [$this->recipe['name'], '', 'Ingredients', ...$ingredients, '', 'Steps', ...$steps]);
    }

    protected function signOut(): void
    {
        app(Session::class)->signOut();
        $this->replace('/sign-in');
    }

    public function render(): View
    {
        return view('native.recipes.recipe-show');
    }
}
