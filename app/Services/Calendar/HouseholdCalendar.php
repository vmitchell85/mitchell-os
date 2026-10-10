<?php

namespace App\Services\Calendar;

use App\Models\SharedCalendar;
use Carbon\CarbonImmutable;

/**
 * Events from the calendars the household has chosen to share.
 */
class HouseholdCalendar
{
    public function __construct(protected MacCalendar $calendar) {}

    public function isShared(): bool
    {
        return SharedCalendar::exists();
    }

    /**
     * @return list<array{calendar: string, title: string, when: string, all_day: bool, start: string, end: string, location: string|null}>
     *
     * @throws CalendarException
     */
    public function events(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $calendars = SharedCalendar::all()->keyBy('calendar_id');

        if ($calendars->isEmpty()) {
            return [];
        }

        $events = $this->calendar->events($from, $to, array_values($calendars->map(fn (SharedCalendar $calendar): string => $calendar->calendar_id)->all()));

        return array_map(fn (CalendarEvent $event): array => [
            'calendar' => $calendars->get($event->calendarId)?->displayName() ?? '',
            'title' => $event->title,
            'when' => $event->when(),
            'all_day' => $event->allDay,
            'start' => $event->allDay ? $event->start->toDateString() : $event->start->toIso8601String(),
            'end' => $event->allDay ? $event->end->toDateString() : $event->end->toIso8601String(),
            'location' => $event->location,
        ], $events);
    }

    /**
     * The household's events in a period, one per line, for a prompt.
     *
     * @throws CalendarException
     */
    public function describe(CarbonImmutable $from, CarbonImmutable $to): ?string
    {
        $lines = array_map(
            fn (array $event): string => "- {$event['when']}: {$event['title']}".($event['calendar'] !== '' ? " ({$event['calendar']})" : ''),
            $this->events($from, $to),
        );

        return $lines === [] ? null : implode("\n", $lines);
    }
}
