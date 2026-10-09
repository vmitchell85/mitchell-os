<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShoppingListItemResource;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Changes reach Skylight at the next sync.
 */
class ShoppingListItemController extends Controller
{
    public function store(Request $request, ShoppingList $shoppingList): ShoppingListItemResource
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'section' => ['nullable', 'string', 'max:100'],
        ]);

        return new ShoppingListItemResource(
            $shoppingList->addItem($validated['name'], ($validated['section'] ?? null) ?: null, $request->user()->id),
        );
    }

    /**
     * Rename, move to another section, or tick off or un-tick an item.
     */
    public function update(Request $request, ShoppingList $shoppingList, ShoppingListItem $item): ShoppingListItemResource
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'section' => ['sometimes', 'nullable', 'string', 'max:100'],
            'completed' => ['sometimes', 'boolean'],
        ]);

        $changes = [];

        foreach (['name', 'section'] as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field] = $validated[$field] ?: null;
            }
        }

        if (array_key_exists('completed', $validated) && (bool) $validated['completed'] !== $item->isCompleted()) {
            $changes['completed_at'] = $validated['completed'] ? now() : null;
        }

        $item->update($changes);

        return new ShoppingListItemResource($item);
    }

    /**
     * Soft-delete, so the next sync can remove it from Skylight too.
     */
    public function destroy(ShoppingList $shoppingList, ShoppingListItem $item): Response
    {
        $item->delete();

        return response()->noContent();
    }
}
