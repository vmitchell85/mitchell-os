<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Meals\BuildShoppingList;
use App\Http\Controllers\Controller;
use App\Models\ShoppingList;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShoppingListProposalController extends Controller
{
    /**
     * Have Claude work out what to buy for the approved meals between two
     * dates. Nothing is added: post the ones to keep to the list's items.
     */
    public function store(Request $request, ShoppingList $shoppingList, BuildShoppingList $buildShoppingList): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return response()->json([
            'data' => $buildShoppingList->handle(
                $request->user(),
                $shoppingList,
                CarbonImmutable::parse($validated['from']),
                CarbonImmutable::parse($validated['to']),
            ),
        ]);
    }
}
