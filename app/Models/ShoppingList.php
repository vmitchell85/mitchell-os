<?php

namespace App\Models;

use Database\Factories\ShoppingListFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A household shopping list, synced with a Skylight shopping list. Lists
 * made here are created in Skylight at the next sync. A list deleted in
 * Skylight is kept here, marked removed_from_skylight_at, and not re-created.
 *
 * @property int $id
 * @property string $name
 * @property string|null $color
 * @property string|null $skylight_id
 * @property array{name: string}|null $skylight_snapshot
 * @property bool $is_default
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $removed_from_skylight_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'color', 'skylight_id', 'skylight_snapshot', 'is_default', 'last_synced_at', 'removed_from_skylight_at'])]
class ShoppingList extends Model
{
    /** @use HasFactory<ShoppingListFactory> */
    use HasFactory;

    /**
     * Colors offered for new lists. Skylight requires one.
     *
     * @var list<string>
     */
    public const array Colors = ['#42D792', '#7DB5F5', '#FDC36D', '#F28B82', '#B39DDB'];

    public const string DefaultColor = '#42D792';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'skylight_snapshot' => 'array',
            'last_synced_at' => 'datetime',
            'removed_from_skylight_at' => 'datetime',
        ];
    }

    /**
     * The household's main grocery list: Skylight's default, otherwise the first by name.
     */
    public static function mainList(): ?self
    {
        return static::orderByDesc('is_default')->orderBy('name')->first();
    }

    /**
     * Add an item to the end of the list.
     */
    public function addItem(string $name, ?string $section = null, ?int $addedBy = null): ShoppingListItem
    {
        return $this->items()->create([
            'name' => $name,
            'section' => $section,
            'position' => (int) $this->items()->max('position') + 1,
            'added_by' => $addedBy,
        ]);
    }

    /**
     * @return HasMany<ShoppingListItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ShoppingListItem::class);
    }

    /**
     * Items still to buy.
     *
     * @return HasMany<ShoppingListItem, $this>
     */
    public function pendingItems(): HasMany
    {
        return $this->items()->whereNull('completed_at');
    }
}
