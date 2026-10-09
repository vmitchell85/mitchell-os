<?php

namespace App\Mcp\Tools;

use App\Models\ShoppingList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('add-shopping-items')]
#[Description('Add items to a shopping list, skipping any that are already on it. Uses the main grocery list when no list is given. Items reach Skylight at the next sync.')]
class AddShoppingItemsTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'list_id' => ['nullable', 'integer', 'exists:shopping_lists,id'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.section' => ['nullable', 'string', 'max:100'],
        ], [
            'list_id.exists' => 'There is no shopping list with that id. Use list-shopping-lists to find one.',
        ]);

        $listId = $request->integer('list_id');
        $list = $listId !== 0 ? ShoppingList::find($listId) : ShoppingList::mainList();

        if ($list === null) {
            return Response::error('The household has no shopping lists yet.');
        }

        $onList = $list->items()->whereNull('completed_at')->pluck('name')->map(fn (string $name): string => Str::lower(trim($name)))->flip();
        $position = (int) $list->items()->max('position');
        $added = [];
        $skipped = [];

        foreach ($validated['items'] as $item) {
            $name = trim($item['name']);

            if ($onList->has(Str::lower($name))) {
                $skipped[] = $name;

                continue;
            }

            $list->items()->create([
                'name' => $name,
                'section' => trim($item['section'] ?? '') ?: null,
                'position' => ++$position,
                'added_by' => $request->user()?->getAuthIdentifier(),
            ]);
            $onList->put(Str::lower($name), true);
            $added[] = $name;
        }

        return Response::structured([
            'list' => $list->name,
            'added' => $added,
            'already_on_list' => $skipped,
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
            'items' => $schema->array()->items($schema->object([
                'name' => $schema->string()->description('What to buy, e.g. "2 pints of milk".')->required(),
                'section' => $schema->string()->description('An aisle or section, ideally one already on the list.'),
            ]))->required(),
        ];
    }
}
