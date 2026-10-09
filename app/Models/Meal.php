<?php

namespace App\Models;

use Database\Factories\MealFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A planned meal. Drafts (not yet approved) are suggestions from the meal
 * planner and never reach Skylight. A repeating Skylight meal arrives as
 * one Meal per date; skylight_date is the date Skylight knows it by, which
 * stays put if the meal is moved here until the next sync.
 *
 * @property int $id
 * @property Carbon $date
 * @property string $category
 * @property string $name
 * @property string|null $description
 * @property string|null $note
 * @property Carbon|null $approved_at
 * @property int|null $added_by
 * @property string|null $skylight_id
 * @property Carbon|null $skylight_date
 * @property bool $repeats_in_skylight
 * @property array{date: string, category: string, name: string, description: string, note: string}|null $skylight_snapshot
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['date', 'category', 'name', 'description', 'note', 'approved_at', 'added_by', 'skylight_id', 'skylight_date', 'repeats_in_skylight', 'skylight_snapshot'])]
class Meal extends Model
{
    /** @use HasFactory<MealFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The categories the planner uses, in the order they're shown.
     *
     * @var list<string>
     */
    public const array Categories = ['Breakfast', 'Lunch', 'Dinner'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'approved_at' => 'datetime',
            'skylight_date' => 'date',
            'repeats_in_skylight' => 'boolean',
            'skylight_snapshot' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /**
     * @param  Builder<Meal>  $query
     */
    #[Scope]
    protected function approved(Builder $query): void
    {
        $query->whereNotNull('approved_at');
    }

    /**
     * @param  Builder<Meal>  $query
     */
    #[Scope]
    protected function drafts(Builder $query): void
    {
        $query->whereNull('approved_at');
    }

    public function isDraft(): bool
    {
        return $this->approved_at === null;
    }

    /**
     * The fields sync compares, in the same shape as skylight_snapshot.
     *
     * @return array{date: string, category: string, name: string, description: string, note: string}
     */
    public function syncableState(): array
    {
        return [
            'date' => $this->date->toDateString(),
            'category' => $this->category,
            'name' => $this->name,
            'description' => $this->description ?? '',
            'note' => $this->note ?? '',
        ];
    }
}
