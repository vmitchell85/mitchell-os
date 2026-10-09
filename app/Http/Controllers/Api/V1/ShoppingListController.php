<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShoppingListResource;
use App\Models\ShoppingList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ShoppingListController extends Controller
{
    /**
     * The household's lists, the main list first.
     */
    public function index(): AnonymousResourceCollection
    {
        return ShoppingListResource::collection(
            ShoppingList::withCount('pendingItems')->orderByDesc('is_default')->orderBy('name')->get(),
        );
    }

    /**
     * Lists made here are created in Skylight at the next sync.
     */
    public function store(Request $request): ShoppingListResource
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'color' => ['sometimes', Rule::in(ShoppingList::Colors)],
        ]);

        return new ShoppingListResource(ShoppingList::create([
            'name' => $validated['name'],
            'color' => $validated['color'] ?? ShoppingList::DefaultColor,
        ]));
    }

    /**
     * The list with its items, ticked off or not, in list order.
     */
    public function show(ShoppingList $shoppingList): ShoppingListResource
    {
        return new ShoppingListResource($shoppingList->load([
            'items' => fn ($query) => $query->orderBy('position'),
        ]));
    }

    public function update(Request $request, ShoppingList $shoppingList): ShoppingListResource
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $shoppingList->update($validated);

        return new ShoppingListResource($shoppingList);
    }
}
