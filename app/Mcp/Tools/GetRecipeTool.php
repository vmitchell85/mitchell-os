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
#[Name('get-recipe')]
#[Description('Get one of the family\'s saved recipes with its ingredients, steps and notes.')]
class GetRecipeTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'recipe_id' => ['required', 'integer'],
        ]);

        $recipe = Recipe::find($request->integer('recipe_id'));

        if ($recipe === null) {
            return Response::error('There is no saved recipe with that id. Use list-recipes to find one.');
        }

        return Response::structured([
            'id' => $recipe->id,
            'name' => $recipe->name,
            'servings' => $recipe->servings,
            'total_minutes' => $recipe->total_minutes,
            'ingredients' => $recipe->ingredientList(),
            'steps' => $recipe->stepList(),
            'notes' => $recipe->notes,
            'source_url' => $recipe->source_url,
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
            'recipe_id' => $schema->integer()->description('The recipe\'s id from list-recipes.')->required(),
        ];
    }
}
