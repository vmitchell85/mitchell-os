<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Meals\RegenerateMeal;
use App\Http\Controllers\Controller;
use App\Http\Resources\MealResource;
use App\Models\Meal;
use Illuminate\Http\Request;

class MealRegenerationController extends Controller
{
    /**
     * Ask Claude for a different suggestion in a draft's place.
     */
    public function __invoke(Request $request, Meal $meal, RegenerateMeal $regenerateMeal): MealResource
    {
        abort_unless($meal->isDraft(), 409, 'Only suggestions awaiting approval can be regenerated.');

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return new MealResource($regenerateMeal->handle($request->user(), $meal, $validated['notes'] ?? ''));
    }
}
