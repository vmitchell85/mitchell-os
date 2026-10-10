<?php

namespace App\Models;

use Database\Factories\HouseholdNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something Claude should always know about the household, such as ages,
 * allergies, routines or preferences. Every chat and food feature gets them.
 *
 * @property int $id
 * @property string $body
 * @property int|null $added_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['body', 'added_by'])]
class HouseholdNote extends Model
{
    /** @use HasFactory<HouseholdNoteFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /**
     * The notes as a prompt section, or null when there are none.
     */
    public static function prompt(): ?string
    {
        $notes = static::oldest('id')->pluck('body');

        return $notes->isNotEmpty()
            ? "About the household (keep these in mind):\n".$notes->map(fn (string $note): string => "- {$note}")->implode("\n")
            : null;
    }
}
