<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Recipes\ImportRecipe;
use App\Actions\Recipes\RecipeImportException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecipeImportController extends Controller
{
    /**
     * Read a recipe from a web page without saving it, so the app can show it
     * for checking and then save it to the recipes endpoint.
     */
    public function __invoke(Request $request, ImportRecipe $importRecipe): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url:http,https', 'max:2048'],
        ]);

        try {
            return response()->json(['data' => $importRecipe->handle($request->user(), $validated['url'])]);
        } catch (RecipeImportException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
