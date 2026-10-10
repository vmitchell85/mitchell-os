<?php

namespace App\Models;

use Database\Factories\SharedCalendarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A macOS Calendar calendar the household has chosen to share with chat and
 * the meal planner. calendar_id is macOS's identifier for it; label says
 * whose it is, such as "Vince – Work".
 *
 * @property int $id
 * @property string $calendar_id
 * @property string $title
 * @property string|null $label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['calendar_id', 'title', 'label'])]
class SharedCalendar extends Model
{
    /** @use HasFactory<SharedCalendarFactory> */
    use HasFactory;

    /**
     * What to call the calendar when telling Claude about its events.
     */
    public function displayName(): string
    {
        return $this->label ?: $this->title;
    }
}
