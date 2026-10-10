<?php

namespace App\Services\Calendar;

use App\Models\SharedCalendar;
use App\Services\MacHelper\MacHelperException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

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
     * @throws MacHelperException
     */
    public function events(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_map(
            fn (array $event): array => array_diff_key($event, ['color' => true]),
            $this->load($from, $to, withColors: false),
        );
    }

    /**
     * The same events, each with its calendar's color ("#RRGGBB", or null when the Mac has none).
     *
     * @return list<array{calendar: string, title: string, when: string, all_day: bool, start: string, end: string, location: string|null, color: string|null}>
     *
     * @throws MacHelperException
     */
    public function eventsWithColor(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->load($from, $to, withColors: true);
    }

    /**
     * @return list<array{calendar: string, title: string, when: string, all_day: bool, start: string, end: string, location: string|null, color: string|null}>
     *
     * @throws MacHelperException
     */
    protected function load(CarbonImmutable $from, CarbonImmutable $to, bool $withColors): array
    {
        $calendars = SharedCalendar::all()->keyBy('calendar_id');

        if ($calendars->isEmpty()) {
            return [];
        }

        $events = $this->calendar->events($from, $to, array_values($calendars->map(fn (SharedCalendar $calendar): string => $calendar->calendar_id)->all()));

        $colors = $withColors ? $this->colors() : [];

        return array_map(fn (CalendarEvent $event): array => [
            'calendar' => $calendars->get($event->calendarId)?->displayName() ?? '',
            'title' => $event->title,
            'when' => $event->when(),
            'all_day' => $event->allDay,
            'start' => $event->allDay ? $event->start->toDateString() : $event->start->toIso8601String(),
            'end' => $event->allDay ? $event->end->toDateString() : $event->end->toIso8601String(),
            'location' => $event->location,
            'color' => $colors[$event->calendarId] ?? null,
        ], $events);
    }

    /**
     * Calendar id => color, cached for an hour. A Mac that can't answer just means no colors.
     *
     * @return array<string, string|null>
     */
    protected function colors(): array
    {
        try {
            return Cache::remember('mac-calendar-colors', now()->addHour(), function (): array {
                $colors = [];

                foreach ($this->calendar->calendars() as $info) {
                    $colors[$info->id] = $info->color;
                }

                return $colors;
            });
        } catch (MacHelperException) {
            return [];
        }
    }

    /**
     * The household's events in a period, one per line, for a prompt.
     *
     * @throws MacHelperException
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
