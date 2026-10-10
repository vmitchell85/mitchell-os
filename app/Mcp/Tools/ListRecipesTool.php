<?php

namespace App\Mcp\Tools;

use App\Models\Recipe;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('list-recipes')]
#[Description('List the family\'s saved recipes, optionally only those whose name contains some text. Use get-recipe for the ingredients and steps.')]
class ListRecipesTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $recipes = Recipe::query()
            ->when($validated['search'] ?? null, fn ($query, string $search) => $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('name')
            ->limit(200)
            ->get();

        return Response::structured([
            'recipes' => $recipes->map(fn (Recipe $recipe): array => [
                'id' => $recipe->id,
                'name' => $recipe->name,
                'total_minutes' => $recipe->total_minutes,
                'servings' => $recipe->servings,
            ])->all(),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Only recipes whose name contains this, e.g. "chicken".'),
        ];
    }
}
