<?php

namespace App\Mcp\Tools;

use App\Models\ShoppingList;
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
#[Name('list-shopping-lists')]
#[Description('List the household\'s shopping lists with how many items are still to buy on each.')]
class ListShoppingListsTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $lists = ShoppingList::orderByDesc('is_default')->orderBy('name')->get();

        return Response::structured([
            'lists' => $lists->map(fn (ShoppingList $list): array => [
                'id' => $list->id,
                'name' => $list->name,
                'is_default' => $list->is_default,
                'items_to_buy' => $list->items()->whereNull('completed_at')->count(),
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
        return [];
    }
}
