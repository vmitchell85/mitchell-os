<?php

namespace App\Services\Calendar;

use Carbon\CarbonImmutable;

/**
 * One occurrence of an event. Times keep the Mac's own UTC offset, so they
 * read as the family's local time; all-day events are whole dates, with
 * end being the last day.
 */
final readonly class CalendarEvent
{
    public function __construct(
        public string $calendarId,
        public string $title,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public bool $allDay,
        public ?string $location,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            calendarId: (string) $attributes['calendar_id'],
            title: (string) $attributes['title'],
            start: CarbonImmutable::parse((string) $attributes['start']),
            end: CarbonImmutable::parse((string) $attributes['end']),
            allDay: (bool) $attributes['all_day'],
            location: isset($attributes['location']) && $attributes['location'] !== '' ? (string) $attributes['location'] : null,
        );
    }

    /**
     * For example "Tue 13 Oct 18:00–19:30" or "Sat 17 Oct (all day)".
     */
    public function when(): string
    {
        if ($this->allDay) {
            return $this->start->isSameDay($this->end)
                ? $this->start->format('D j M').' (all day)'
                : $this->start->format('D j M').' – '.$this->end->format('D j M').' (all day)';
        }

        return $this->start->isSameDay($this->end)
            ? $this->start->format('D j M H:i').'–'.$this->end->format('H:i')
            : $this->start->format('D j M H:i').' – '.$this->end->format('D j M H:i');
    }
}
