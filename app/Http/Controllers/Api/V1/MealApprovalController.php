<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MealResource;
use App\Models\Meal;

class MealApprovalController extends Controller
{
    /**
     * Approve a draft, so it reaches Skylight at the next sync.
     */
    public function __invoke(Meal $meal): MealResource
    {
        if ($meal->isDraft()) {
            $meal->update(['approved_at' => now()]);
        }

        return new MealResource($meal);
    }
}
