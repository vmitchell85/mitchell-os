<?php

namespace App\Services\Dashboard;

use App\Models\HouseholdSetting;
use App\Services\Calendar\HouseholdCalendar;
use App\Services\MacHelper\MacDirections;
use App\Services\MacHelper\MacHelper;
use App\Services\MacHelper\MacHelperException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class Dashboard
{
    public const int ScheduleDays = 3;

    public const int DriveCacheMinutes = 10;

    public function __construct(
        protected Weather $weather,
        protected HouseholdCalendar $calendar,
        protected MacDirections $directions,
        protected MacHelper $helper,
    ) {}

    /**
     * Everything the dashboard shows. All times are ISO 8601 UTC; clients display Eastern.
     *
     * @return array{
     *     generated_at: string,
     *     weather: array{
     *         location: string, temperature_f: int, conditions: string, icon: string,
     *         high_f: int|null, low_f: int|null, precip_chance: int|null,
     *         hourly: list<array{time: string, temperature_f: int, conditions: string, icon: string, precip_chance: int|null}>,
     *         alerts: list<array{headline: string, severity: string}>,
     *     }|null,
     *     weather_error: string|null,
     *     schedule: list<array{date: string, label: string, events: list<array{
     *         title: string, calendar: string, when: string, all_day: bool, start: string, end: string, location: string|null,
     *         drive: array{minutes: int, miles: float, leave_by: string, traffic_aware: bool, maps_url: string}|null,
     *     }>}>,
     *     schedule_error: string|null,
     *     drive_error: string|null,
     *     home_address_set: bool,
     * }
     */
    public function summary(): array
    {
        $weather = null;
        $weatherError = null;

        try {
            $weather = $this->weather->forZip(HouseholdSetting::weatherZip());
        } catch (WeatherException $exception) {
            $weatherError = $exception->getMessage();
        }

        $homeAddress = HouseholdSetting::homeAddress();
        [$schedule, $scheduleError] = $this->schedule();
        $driveError = $homeAddress !== null ? $this->addDrives($schedule, $homeAddress) : null;

        return [
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'weather' => $weather,
            'weather_error' => $weatherError,
            'schedule' => array_map(fn (array $day): array => [
                'date' => $day['date'],
                'label' => $day['label'],
                'events' => array_map(fn (array $event): array => $this->present($event), $day['events']),
            ], $schedule),
            'schedule_error' => $scheduleError,
            'drive_error' => $driveError,
            'home_address_set' => $homeAddress !== null,
        ];
    }

    /**
     * Today and the next days, by Eastern day boundaries, with every day present.
     *
     * @return array{0: list<array{date: string, label: string, events: list<array<string, mixed>>}>, 1: string|null}
     */
    protected function schedule(): array
    {
        $timezone = config('app.display_timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $error = null;
        $events = [];

        try {
            if ($this->calendar->isShared()) {
                $events = $this->calendar->events($today, $today->addDays(self::ScheduleDays)->subSecond());
            } else {
                $error = 'No calendars are shared yet. Choose some under Settings → Calendars.';
            }
        } catch (MacHelperException $exception) {
            $error = $exception->getMessage();
        }

        $days = [];

        for ($offset = 0; $offset < self::ScheduleDays; $offset++) {
            $start = $today->addDays($offset);
            $end = $start->addDay();

            $days[] = [
                'date' => $start->toDateString(),
                'label' => match ($offset) {
                    0 => 'Today',
                    1 => 'Tomorrow',
                    default => $start->format('l, F j'),
                },
                'events' => $this->eventsOn($events, $start, $end, $timezone),
            ];
        }

        return [$days, $error];
    }

    /**
     * @param  list<array{calendar: string, title: string, when: string, all_day: bool, start: string, end: string, location: string|null}>  $events
     * @return list<array<string, mixed>>
     */
    protected function eventsOn(array $events, CarbonImmutable $dayStart, CarbonImmutable $dayEnd, string $timezone): array
    {
        $onDay = [];

        foreach ($events as $event) {
            $start = CarbonImmutable::parse($event['start'], $timezone)->setTimezone($timezone);
            $end = CarbonImmutable::parse($event['end'], $timezone)->setTimezone($timezone);

            $overlaps = $event['all_day']
                ? $start->lessThan($dayEnd) && $end->greaterThanOrEqualTo($dayStart)
                : $start->lessThan($dayEnd) && ($end->greaterThan($dayStart) || $start->greaterThanOrEqualTo($dayStart));

            if ($overlaps) {
                $onDay[] = $event + ['starts_at' => $start, 'drive' => null];
            }
        }

        usort($onDay, fn (array $a, array $b): int => [! $a['all_day'], $a['starts_at']->getTimestamp(), $a['title']] <=> [! $b['all_day'], $b['starts_at']->getTimestamp(), $b['title']]);

        return $onDay;
    }

    /**
     * Adds drive estimates to today's remaining timed events that have a location. Stops at the
     * first failure, since the helper is usually missing or broken for every event.
     *
     * @param  list<array{date: string, label: string, events: list<array<string, mixed>>}>  $schedule
     */
    protected function addDrives(array &$schedule, string $homeAddress): ?string
    {
        if ($schedule === []) {
            return null;
        }

        $now = CarbonImmutable::now();

        $candidates = array_filter($schedule[0]['events'], fn (array $event): bool => ! $event['all_day']
            && $event['location'] !== null
            && $event['starts_at']->greaterThan($now));

        if ($candidates === []) {
            return null;
        }

        if (! $this->helper->isInstalled()) {
            return MacHelperException::notInstalled()->getMessage();
        }

        foreach ($candidates as $index => $event) {
            try {
                $schedule[0]['events'][$index]['drive'] = $this->drive($homeAddress, $event['location'], $event['starts_at']);
            } catch (MacHelperException $exception) {
                return $exception->getMessage();
            }
        }

        return null;
    }

    /**
     * @return array{minutes: int, miles: float, leave_by: CarbonImmutable, traffic_aware: bool, maps_url: string}
     *
     * @throws MacHelperException
     */
    protected function drive(string $from, string $to, CarbonImmutable $arriveBy): array
    {
        $key = 'dashboard.drive.'.sha1("{$from}|{$to}|{$arriveBy->toIso8601String()}");

        $estimate = Cache::get($key);

        if (! is_array($estimate)) {
            $result = $this->directions->drive($from, $to, $arriveBy);
            $estimate = ['minutes' => $result->minutes, 'miles' => $result->miles, 'traffic_aware' => $result->trafficAware];
            Cache::put($key, $estimate, now()->addMinutes(self::DriveCacheMinutes));
        }

        return $estimate + [
            'leave_by' => $arriveBy->subMinutes($estimate['minutes']),
            'maps_url' => 'https://maps.apple.com/?'.http_build_query(['saddr' => $from, 'daddr' => $to, 'dirflg' => 'd'], '', '&', PHP_QUERY_RFC3986),
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    protected function present(array $event): array
    {
        $drive = $event['drive'];

        return [
            'title' => $event['title'],
            'calendar' => $event['calendar'],
            'when' => $event['when'],
            'all_day' => $event['all_day'],
            'start' => $event['all_day'] ? $event['start'] : $event['starts_at']->utc()->toIso8601String(),
            'end' => $event['all_day'] ? $event['end'] : CarbonImmutable::parse($event['end'])->utc()->toIso8601String(),
            'location' => $event['location'],
            'drive' => $drive === null ? null : [
                'minutes' => $drive['minutes'],
                'miles' => $drive['miles'],
                'leave_by' => $drive['leave_by']->utc()->toIso8601String(),
                'traffic_aware' => $drive['traffic_aware'],
                'maps_url' => $drive['maps_url'],
            ],
        ];
    }
}
