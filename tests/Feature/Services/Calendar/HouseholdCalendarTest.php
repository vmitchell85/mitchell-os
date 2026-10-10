<?php

use App\Models\SharedCalendar;
use App\Services\Calendar\HouseholdCalendar;
use Carbon\CarbonImmutable;

function householdEvents(): array
{
    return [['calendar_id' => 'HOME-1', 'title' => 'Football', 'all_day' => false, 'start' => '2026-10-13T18:00:00-04:00', 'end' => '2026-10-13T19:30:00-04:00', 'location' => null]];
}

function colorsFor(): array
{
    return app(HouseholdCalendar::class)->eventsWithColor(CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-10-18'));
}

beforeEach(function () {
    SharedCalendar::factory()->create(['calendar_id' => 'HOME-1', 'title' => 'Family']);
});

it('colors events by their calendar', function () {
    fakeMacHelper(calendars: [['id' => 'HOME-1', 'title' => 'Family', 'account' => 'a', 'color' => '#4285F4']], events: householdEvents());

    expect(colorsFor()[0]['color'])->toBe('#4285F4');
});

it('reads the calendar colors once an hour', function () {
    $captured = fakeMacHelper(calendars: [['id' => 'HOME-1', 'title' => 'Family', 'account' => 'a', 'color' => '#4285F4']], events: householdEvents());

    colorsFor();
    colorsFor();

    expect(collect($captured->runs)->where(0, 'calendars'))->toHaveCount(1)
        ->and(collect($captured->runs)->where(0, 'events'))->toHaveCount(2);
});

it('still returns the events, without colors, when the colors cannot be read', function () {
    fakeMacHelper(events: householdEvents());
    Process::fake(["'open' '-W' *" => function ($process) {
        $command = $process->command;
        $arguments = array_slice($command, array_search('--args', $command, true) + 1);

        if ($arguments[0] === 'calendars') {
            file_put_contents($command[array_search('--stderr', $command, true) + 1], 'Calendar access is turned off.');
        } else {
            file_put_contents($command[array_search('--stdout', $command, true) + 1], json_encode(householdEvents()));
        }

        return Process::describe();
    }]);

    $events = colorsFor();

    expect($events)->toHaveCount(1)->and($events[0]['color'])->toBeNull();
});
