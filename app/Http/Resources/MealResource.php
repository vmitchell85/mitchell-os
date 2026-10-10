<?php

namespace App\Http\Resources;

use App\Models\Meal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Meal
 */
class MealResource extends JsonResource
{
    /**
     * Transform the resource into an array. Drafts are Claude's suggestions
     * awaiting approval; they don't reach Skylight until approved.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'category' => $this->category,
            'name' => $this->name,
            'ingredients' => $this->ingredients(),
            'note' => $this->note,
            'recipe_id' => $this->recipe_id,
            'is_draft' => $this->isDraft(),
            'approved_at' => $this->approved_at,
            'added_by' => $this->added_by,
            'repeats_in_skylight' => $this->repeats_in_skylight,
        ];
    }
}
