<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecipeResource;
use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class RecipeController extends Controller
{
    /**
     * The household's saved recipes by name, optionally filtered by `search`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return RecipeResource::collection(
            Recipe::query()
                ->when($validated['search'] ?? null, fn ($query, string $search) => $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
                ->orderBy('name')
                ->get(),
        );
    }

    public function store(Request $request): RecipeResource
    {
        $validated = $request->validate($this->rules());

        return new RecipeResource(Recipe::create([...$this->attributes($validated), 'added_by' => $request->user()->id]));
    }

    public function show(Recipe $recipe): RecipeResource
    {
        return new RecipeResource($recipe);
    }

    public function update(Request $request, Recipe $recipe): RecipeResource
    {
        $validated = $request->validate($this->rules(partial: true));

        $recipe->update($this->attributes($validated));

        return new RecipeResource($recipe);
    }

    /**
     * Meals planned from it keep their own copy of the ingredients.
     */
    public function destroy(Recipe $recipe): Response
    {
        $recipe->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function rules(bool $partial = false): array
    {
        return [
            'name' => [...($partial ? ['sometimes'] : []), 'required', 'string', 'max:255'],
            'ingredients' => ['sometimes', 'nullable', 'array', 'max:100'],
            'ingredients.*' => ['nullable', 'string', 'max:255'],
            'steps' => ['sometimes', 'nullable', 'array', 'max:100'],
            'steps.*' => ['nullable', 'string', 'max:2000'],
            'servings' => ['sometimes', 'nullable', 'string', 'max:50'],
            'total_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'source_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function attributes(array $validated): array
    {
        $attributes = [];

        foreach ($validated as $field => $value) {
            $attributes[$field] = match ($field) {
                'ingredients', 'steps' => Recipe::joinLines($value ?? []),
                'name' => trim((string) $value),
                'total_minutes' => $value ?: null,
                default => is_string($value) ? (trim($value) ?: null) : $value,
            };
        }

        return $attributes;
    }
}
