<?php

namespace App\Services\Calendar;

use App\Services\MacHelper\MacHelper;
use App\Services\MacHelper\MacHelperException;
use Carbon\CarbonImmutable;

/**
 * Reads macOS Calendar through the Mac helper app.
 */
class MacCalendar
{
    public function __construct(protected MacHelper $helper) {}

    public function isInstalled(): bool
    {
        return $this->helper->isInstalled();
    }

    /**
     * @return list<MacCalendarInfo>
     *
     * @throws MacHelperException
     */
    public function calendars(): array
    {
        return array_map(MacCalendarInfo::fromArray(...), $this->list(['calendars']));
    }

    /**
     * Occurrences that overlap the period, from the given calendars only.
     *
     * @param  list<string>  $calendarIds
     * @return list<CalendarEvent>
     *
     * @throws MacHelperException
     */
    public function events(CarbonImmutable $from, CarbonImmutable $to, array $calendarIds): array
    {
        if ($calendarIds === []) {
            return [];
        }

        return array_map(CalendarEvent::fromArray(...), $this->list([
            'events', $from->toIso8601String(), $to->toIso8601String(), implode(',', $calendarIds),
        ]));
    }

    /**
     * @param  list<string>  $arguments
     * @return list<array<string, mixed>>
     *
     * @throws MacHelperException
     */
    protected function list(array $arguments): array
    {
        $decoded = $this->helper->run($arguments);

        if (! array_is_list($decoded)) {
            throw new MacHelperException('The Mac helper returned something unexpected.');
        }

        return array_values(array_filter($decoded, is_array(...)));
    }
}
