<?php

namespace App\Mcp\Tools;

use App\Models\Meal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('suggest-meal')]
#[Description('Suggest a meal for a date. It is saved as a suggestion that the family approves on the Meals page before it is planned or sent to Skylight.')]
class SuggestMealTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'category' => ['required', Rule::in(Meal::Categories)],
            'name' => ['required', 'string', 'max:255'],
            'ingredients' => ['array'],
            'ingredients.*' => ['string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'category.in' => 'The category must be one of: '.implode(', ', Meal::Categories).'.',
        ]);

        $ingredients = array_filter(array_map('trim', $validated['ingredients'] ?? []));

        $meal = Meal::create([
            'date' => $validated['date'],
            'category' => $validated['category'],
            'name' => trim($validated['name']),
            'description' => $ingredients !== [] ? implode("\n", $ingredients) : null,
            'note' => trim($validated['note'] ?? '') ?: null,
            'approved_at' => null,
            'added_by' => $request->user()?->getAuthIdentifier(),
        ]);

        return Response::text("Suggested {$meal->name} for {$meal->category} on {$meal->date->format('l, F j')}. The family can approve it on the Meals page.");
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()->description('YYYY-MM-DD.')->required(),
            'category' => $schema->string()->enum(Meal::Categories)->required(),
            'name' => $schema->string()->description('The meal, e.g. "Chicken fajitas".')->required(),
            'ingredients' => $schema->array()->items($schema->string())->description('What to buy, with rough quantities, e.g. "1 lb ground beef".'),
            'note' => $schema->string()->description('A short tip, such as prep time.'),
        ];
    }
}
