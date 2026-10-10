<?php

use App\Services\Calendar\CalendarException;
use App\Services\Calendar\MacCalendar;
use Carbon\CarbonImmutable;

it('lists the calendars on this mac', function () {
    fakeCalendarHelper(calendars: [
        ['id' => 'A1', 'title' => 'Work', 'account' => 'vince@example.com', 'color' => '#4285F4'],
    ]);

    $calendars = app(MacCalendar::class)->calendars();

    expect($calendars)->toHaveCount(1)
        ->and($calendars[0])->id->toBe('A1')->title->toBe('Work')->account->toBe('vince@example.com');
});

it('asks for events from the chosen calendars only and keeps their local times', function () {
    $captured = fakeCalendarHelper(events: [
        ['calendar_id' => 'A1', 'title' => 'Football', 'all_day' => false, 'start' => '2026-10-13T18:00:00-04:00', 'end' => '2026-10-13T19:30:00-04:00', 'location' => null],
        ['calendar_id' => 'A1', 'title' => 'Half term', 'all_day' => true, 'start' => '2026-10-17', 'end' => '2026-10-18', 'location' => ''],
    ]);

    $events = app(MacCalendar::class)->events(CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-10-18 23:59:59'), ['A1', 'B2']);

    expect($captured->runs[0][0])->toBe('events')
        ->and($captured->runs[0][3])->toBe('A1,B2')
        ->and($events[0]->when())->toBe('Tue 13 Oct 18:00–19:30')
        ->and($events[1]->when())->toBe('Sat 17 Oct – Sun 18 Oct (all day)')
        ->and($events[1]->location)->toBeNull();
});

it('does not run the helper when no calendars are asked for', function () {
    $captured = fakeCalendarHelper();

    expect(app(MacCalendar::class)->events(CarbonImmutable::now(), CarbonImmutable::now(), []))->toBe([]);
    expect($captured->runs)->toBe([]);
});

it('reports what the helper says went wrong, such as access being turned off', function () {
    fakeCalendarHelper(error: 'Calendar access is turned off.');

    app(MacCalendar::class)->calendars();
})->throws(CalendarException::class, 'Calendar access is turned off.');

it('explains how to install the helper when it is missing', function () {
    config(['calendar.app_path' => sys_get_temp_dir().'/missing-'.bin2hex(random_bytes(4)).'.app']);

    app(MacCalendar::class)->calendars();
})->throws(CalendarException::class, 'php artisan calendar:install');

it('shows event times in the household\'s timezone', function () {
    fakeCalendarHelper(events: [
        ['calendar_id' => 'A1', 'title' => 'Call', 'all_day' => false, 'start' => '2026-10-13T22:00:00+00:00', 'end' => '2026-10-13T23:00:00+00:00', 'location' => null],
    ]);

    $events = app(MacCalendar::class)->events(CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-10-18'), ['A1']);

    expect($events[0]->when())->toBe('Tue 13 Oct 18:00–19:00');
});
