<?php

namespace App\Models;

use Database\Factories\RecipeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A recipe the household has saved. Ingredients and steps are stored one
 * per line. Meals planned from it link back to it.
 *
 * @property int $id
 * @property string $name
 * @property string|null $ingredients
 * @property string|null $steps
 * @property string|null $servings
 * @property int|null $total_minutes
 * @property string|null $notes
 * @property string|null $source_url
 * @property int|null $added_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'ingredients', 'steps', 'servings', 'total_minutes', 'notes', 'source_url', 'added_by'])]
class Recipe extends Model
{
    /** @use HasFactory<RecipeFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /**
     * @return HasMany<Meal, $this>
     */
    public function meals(): HasMany
    {
        return $this->hasMany(Meal::class);
    }

    /**
     * @return list<string>
     */
    public function ingredientList(): array
    {
        return self::lines($this->ingredients);
    }

    /**
     * @return list<string>
     */
    public function stepList(): array
    {
        return self::lines($this->steps);
    }

    /**
     * Join list items into the one-per-line form recipes are stored in.
     *
     * @param  array<int, mixed>  $items
     */
    public static function joinLines(array $items): ?string
    {
        $lines = self::lines(implode("\n", array_map(fn (mixed $item): string => (string) $item, $items)));

        return $lines !== [] ? implode("\n", $lines) : null;
    }

    /**
     * @return list<string>
     */
    protected static function lines(?string $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $text ?? '')))));
    }
}
