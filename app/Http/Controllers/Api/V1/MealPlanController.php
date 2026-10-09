<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Meals\DraftMealPlan;
use App\Http\Controllers\Controller;
use App\Http\Resources\MealResource;
use App\Models\Meal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class MealPlanController extends Controller
{
    /**
     * Have Claude suggest meals for a week's empty slots, as drafts to approve.
     */
    public function store(Request $request, DraftMealPlan $draftMealPlan): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'week' => ['required', 'date'],
            'categories' => ['sometimes', 'array', 'min:1'],
            'categories.*' => [Rule::in(Meal::Categories)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $drafts = $draftMealPlan->handle(
            $request->user(),
            CarbonImmutable::parse($validated['week'])->startOfWeek(),
            $validated['categories'] ?? ['Dinner'],
            $validated['notes'] ?? '',
        );

        return MealResource::collection($drafts);
    }
}
