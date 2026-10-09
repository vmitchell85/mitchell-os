<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\HouseholdSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The household's food rules, which every feature that suggests food follows.
 */
class FoodRulesController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => ['food_rules' => HouseholdSetting::get(HouseholdSetting::FoodRules)]]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'food_rules' => ['present', 'nullable', 'string', 'max:2000'],
        ]);

        HouseholdSetting::put(HouseholdSetting::FoodRules, trim($validated['food_rules'] ?? '') ?: null);

        return $this->show();
    }
}
