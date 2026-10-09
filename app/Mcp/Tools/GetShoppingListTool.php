<?php

namespace App\Mcp\Tools;

use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
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
#[Name('get-shopping-list')]
#[Description('Get what is still to buy on a shopping list, grouped by section. Uses the main grocery list when no list is given.')]
class GetShoppingListTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'list_id' => ['nullable', 'integer', 'exists:shopping_lists,id'],
        ], [
            'list_id.exists' => 'There is no shopping list with that id. Use list-shopping-lists to find one.',
        ]);

        $listId = $request->integer('list_id');
        $list = $listId !== 0 ? ShoppingList::find($listId) : ShoppingList::mainList();

        if ($list === null) {
            return Response::error('The household has no shopping lists yet.');
        }

        $items = $list->items()->whereNull('completed_at')->orderBy('position')->get();

        return Response::structured([
            'list' => ['id' => $list->id, 'name' => $list->name],
            'sections' => $items->groupBy(fn (ShoppingListItem $item): string => $item->section ?? 'Other')
                ->map(fn ($sectionItems) => $sectionItems->pluck('name')->all())
                ->all(),
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
            'list_id' => $schema->integer()->description('The list\'s id from list-shopping-lists. Defaults to the main grocery list.'),
        ];
    }
}
