<?php

namespace App\Http\Resources;

use App\Models\ShoppingList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ShoppingList
 */
class ShoppingListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            'is_default' => $this->is_default,
            'in_skylight' => $this->skylight_id !== null && $this->removed_from_skylight_at === null,
            'last_synced_at' => $this->last_synced_at,
            'pending_items_count' => $this->whenCounted('pendingItems'),
            'items' => ShoppingListItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
