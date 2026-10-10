<?php

namespace App\Services\Calendar;

use Carbon\CarbonImmutable;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Reads macOS Calendar through the helper app built by calendar:install.
 * The helper is launched with `open` so macOS treats it as its own app and
 * asks for calendar access on its behalf; its output comes back through
 * temporary files because `open` doesn't pass it through.
 */
class MacCalendar
{
    public function isInstalled(): bool
    {
        return is_file($this->executable());
    }

    /**
     * @return list<MacCalendarInfo>
     *
     * @throws CalendarException
     */
    public function calendars(): array
    {
        return array_map(MacCalendarInfo::fromArray(...), $this->run(['calendars']));
    }

    /**
     * Occurrences that overlap the period, from the given calendars only.
     *
     * @param  list<string>  $calendarIds
     * @return list<CalendarEvent>
     *
     * @throws CalendarException
     */
    public function events(CarbonImmutable $from, CarbonImmutable $to, array $calendarIds): array
    {
        if ($calendarIds === []) {
            return [];
        }

        return array_map(CalendarEvent::fromArray(...), $this->run([
            'events', $from->toIso8601String(), $to->toIso8601String(), implode(',', $calendarIds),
        ]));
    }

    public function executable(): string
    {
        return config('calendar.app_path').'/Contents/MacOS/calendar-helper';
    }

    /**
     * @param  list<string>  $arguments
     * @return list<array<string, mixed>>
     *
     * @throws CalendarException
     */
    protected function run(array $arguments): array
    {
        if (! $this->isInstalled()) {
            throw CalendarException::notInstalled();
        }

        $directory = storage_path('app/calendar/runs/'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($directory);
        $output = "{$directory}/stdout";
        $errors = "{$directory}/stderr";

        try {
            $process = Process::timeout((int) config('calendar.timeout'))->run([
                'open', '-W', '-n', '-g', '--stdout', $output, '--stderr', $errors, config('calendar.app_path'), '--args', ...$arguments,
            ]);
        } catch (ProcessTimedOutException) {
            File::deleteDirectory($directory);

            throw new CalendarException('The calendar helper didn\'t answer in time. If macOS is asking to allow calendar access, click Allow and try again.');
        }

        try {
            $error = trim((string) @file_get_contents($errors));

            if ($error !== '' || $process->failed()) {
                throw new CalendarException($error !== '' ? $error : (trim($process->errorOutput()) ?: 'The calendar helper failed.'));
            }

            $decoded = json_decode((string) @file_get_contents($output), true);

            if (! is_array($decoded) || ! array_is_list($decoded)) {
                throw new CalendarException('The calendar helper returned something unexpected.');
            }

            return array_values(array_filter($decoded, is_array(...)));
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
