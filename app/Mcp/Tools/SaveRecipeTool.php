<?php

namespace App\Mcp\Tools;

use App\Models\Recipe;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('save-recipe')]
#[Description('Save a recipe to the family\'s recipes. Only use this when the person chatting asks you to save one. They can edit it on the Recipes page.')]
class SaveRecipeTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'ingredients' => ['required', 'array', 'min:1', 'max:100'],
            'ingredients.*' => ['string', 'max:255'],
            'steps' => ['array', 'max:100'],
            'steps.*' => ['string', 'max:2000'],
            'servings' => ['nullable', 'string', 'max:50'],
            'total_minutes' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'source_url' => ['nullable', 'url:http,https', 'max:2048'],
        ]);

        $recipe = Recipe::create([
            'name' => trim($validated['name']),
            'ingredients' => Recipe::joinLines($validated['ingredients']),
            'steps' => Recipe::joinLines($validated['steps'] ?? []),
            'servings' => trim($validated['servings'] ?? '') ?: null,
            'total_minutes' => ($validated['total_minutes'] ?? 0) ?: null,
            'notes' => trim($validated['notes'] ?? '') ?: null,
            'source_url' => $validated['source_url'] ?? null,
            'added_by' => $request->user()?->getAuthIdentifier(),
        ]);

        return Response::text("Saved the recipe \"{$recipe->name}\" (id {$recipe->id}). The family can find it on the Recipes page.");
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required(),
            'ingredients' => $schema->array()->items($schema->string())->description('One ingredient per item, with US quantities, e.g. "1 lb ground beef".')->required(),
            'steps' => $schema->array()->items($schema->string())->description('One step per item, without numbers.'),
            'servings' => $schema->string()->description('For example "4".'),
            'total_minutes' => $schema->integer()->description('Total time in minutes.'),
            'notes' => $schema->string()->description('Short tips worth keeping.'),
            'source_url' => $schema->string()->description('Where the recipe came from, if it was a web page.'),
        ];
    }
}
