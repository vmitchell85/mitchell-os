<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\Dashboard;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * The weather, the next few days of the household's schedule and drive times to today's events.
     */
    public function __invoke(Dashboard $dashboard): JsonResponse
    {
        return response()->json(['data' => $dashboard->summary()]);
    }
}
