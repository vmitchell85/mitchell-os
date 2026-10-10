<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\HouseholdSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where the household lives, for the dashboard's weather and drive times.
 */
class HouseholdSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            'weather_zip' => HouseholdSetting::weatherZip(),
            'home_address' => HouseholdSetting::homeAddress(),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'weather_zip' => ['required', 'string', 'regex:/^\d{5}$/'],
            'home_address' => ['present', 'nullable', 'string', 'max:255'],
        ]);

        HouseholdSetting::put(HouseholdSetting::WeatherZip, $validated['weather_zip']);
        HouseholdSetting::put(HouseholdSetting::HomeAddress, trim($validated['home_address'] ?? '') ?: null);

        return $this->show();
    }
}
