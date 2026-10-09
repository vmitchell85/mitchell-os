<?php

namespace App\Models;

use Database\Factories\ShoppingListItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Deleting an item soft-deletes it, so the next sync can remove it from
 * Skylight too. skylight_snapshot is the state both sides agreed on at the
 * last sync, which is how sync tells which side changed what.
 *
 * @property int $id
 * @property int $shopping_list_id
 * @property int|null $added_by
 * @property string $name
 * @property string|null $section
 * @property int $position
 * @property Carbon|null $completed_at
 * @property string|null $skylight_id
 * @property array{name: string, section: string|null, completed: bool}|null $skylight_snapshot
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'section', 'position', 'completed_at', 'skylight_id', 'skylight_snapshot', 'added_by'])]
class ShoppingListItem extends Model
{
    /** @use HasFactory<ShoppingListItemFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'skylight_snapshot' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ShoppingList, $this>
     */
    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * The fields sync compares, in the same shape as skylight_snapshot.
     *
     * @return array{name: string, section: string|null, completed: bool}
     */
    public function syncableState(): array
    {
        return ['name' => $this->name, 'section' => $this->section, 'completed' => $this->isCompleted()];
    }
}
