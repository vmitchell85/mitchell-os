<?php

namespace App\Mcp\Tools;

use App\Models\Meal;
use Carbon\CarbonImmutable;
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
#[Name('list-meals')]
#[Description('List planned meals between two dates, including suggestions still awaiting approval, with their ingredients and notes.')]
class ListMealsTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ], [
            'to.after_or_equal' => 'The "to" date must be on or after the "from" date.',
        ]);

        $meals = Meal::whereBetween('date', [CarbonImmutable::parse($validated['from']), CarbonImmutable::parse($validated['to'])])
            ->orderBy('date')->get();

        return Response::structured([
            'meals' => $meals->map(fn (Meal $meal): array => [
                'id' => $meal->id,
                'date' => $meal->date->toDateString(),
                'day' => $meal->date->format('l'),
                'category' => $meal->category,
                'name' => $meal->name,
                'status' => $meal->isDraft() ? 'suggestion awaiting approval' : 'planned',
                'ingredients' => $meal->ingredients(),
                'note' => $meal->note,
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
            'from' => $schema->string()->description('First date, YYYY-MM-DD.')->required(),
            'to' => $schema->string()->description('Last date, YYYY-MM-DD.')->required(),
        ];
    }
}
