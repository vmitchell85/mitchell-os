<?php

use App\Models\HouseholdSetting;
use App\Models\SharedCalendar;
use App\Services\Dashboard\Dashboard;
use App\Services\MacHelper\MacDirections;
use App\Services\MacHelper\MacHelperException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function fakeWeather(array $overrides = []): void
{
    $period = fn (string $start, int $temperature, string $forecast, bool $daytime, ?int $rain = null): array => [
        'startTime' => $start,
        'endTime' => CarbonImmutable::parse($start)->addHour()->toIso8601String(),
        'temperature' => $temperature,
        'shortForecast' => $forecast,
        'isDaytime' => $daytime,
        'probabilityOfPrecipitation' => ['value' => $rain],
    ];

    $now = CarbonImmutable::now()->startOfHour();
    $today = CarbonImmutable::now(config('app.display_timezone'))->startOfDay();

    Http::fake($overrides + [
        'api.zippopotam.us/*' => Http::response(['places' => [['place name' => 'Westerville', 'state abbreviation' => 'OH', 'latitude' => '40.1262', 'longitude' => '-82.9291']]]),
        'api.weather.gov/points/*' => Http::response(['properties' => [
            'forecast' => 'https://api.weather.gov/gridpoints/ILN/1,1/forecast',
            'forecastHourly' => 'https://api.weather.gov/gridpoints/ILN/1,1/forecast/hourly',
        ]]),
        'api.weather.gov/gridpoints/*/forecast/hourly' => Http::response(['properties' => ['periods' => [
            $period($now->subHour()->toIso8601String(), 60, 'Sunny', true),
            $period($now->toIso8601String(), 64, 'Light Rain', true, 40),
            ...array_map(fn (int $hour): array => $period($now->addHours($hour)->toIso8601String(), 60 + $hour, 'Mostly Sunny', true, 10), range(1, 8)),
        ]]]),
        'api.weather.gov/gridpoints/*/forecast' => Http::response(['properties' => ['periods' => [
            $period($today->setHour(6)->toIso8601String(), 72, 'Sunny', true),
            $period($today->setHour(18)->toIso8601String(), 51, 'Clear', false),
            $period($today->addDay()->setHour(6)->toIso8601String(), 80, 'Sunny', true),
        ]]]),
        'api.weather.gov/alerts/active*' => Http::response(['features' => [['properties' => ['headline' => 'Frost Advisory issued for Franklin County', 'severity' => 'Minor']]]]),
    ]);
}

function sharedEvent(string $title, CarbonImmutable $start, ?string $location = null, bool $allDay = false, ?CarbonImmutable $end = null): array
{
    return [
        'calendar_id' => 'HOME-1',
        'title' => $title,
        'start' => $allDay ? $start->toDateString() : $start->toIso8601String(),
        'end' => $allDay ? ($end ?? $start)->toDateString() : ($end ?? $start->addHour())->toIso8601String(),
        'all_day' => $allDay,
        'location' => $location,
    ];
}

beforeEach(function () {
    Cache::flush();
    SharedCalendar::factory()->create(['calendar_id' => 'HOME-1', 'title' => 'Family']);
});

it('reports the weather from the National Weather Service', function () {
    fakeWeather();
    fakeMacHelper();

    $weather = app(Dashboard::class)->summary()['weather'];

    expect($weather)
        ->location->toBe('Westerville, OH 43081')
        ->temperature_f->toBe(64)
        ->conditions->toBe('Light Rain')
        ->icon->toBe('rain')
        ->high_f->toBe(72)
        ->low_f->toBe(51)
        ->precip_chance->toBe(40)
        ->and($weather['hourly'])->toHaveCount(6)
        ->and($weather['hourly'][0])->toMatchArray(['temperature_f' => 61, 'conditions' => 'Mostly Sunny', 'icon' => 'partly-cloudy', 'precip_chance' => 10])
        ->and($weather['alerts'])->toBe([['headline' => 'Frost Advisory issued for Franklin County', 'severity' => 'Minor']]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.weather.gov') && str_contains($request->header('User-Agent')[0], 'MitchellOS'));
});

it('looks up the household zip code and caches the forecast', function () {
    fakeWeather();
    fakeMacHelper();
    HouseholdSetting::put(HouseholdSetting::WeatherZip, '43215');

    app(Dashboard::class)->summary();
    app(Dashboard::class)->summary();

    Http::assertSentCount(5);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.zippopotam.us/us/43215'));
});

it('shows a weather error instead of failing', function () {
    fakeWeather(['api.weather.gov/points/*' => Http::response([], 500)]);
    fakeMacHelper();

    $summary = app(Dashboard::class)->summary();

    expect($summary['weather'])->toBeNull()
        ->and($summary['weather_error'])->toBe('The National Weather Service has no forecast for that location.')
        ->and($summary['schedule'])->toHaveCount(3);
});

it('reports an unreachable weather service', function () {
    fakeWeather(['api.zippopotam.us/*' => fn () => throw new ConnectionException('offline')]);
    fakeMacHelper();

    expect(app(Dashboard::class)->summary()['weather_error'])->toBe("I couldn't reach the weather service.");
});

it('reports a zip code it cannot find', function () {
    fakeWeather(['api.zippopotam.us/*' => Http::response([], 404)]);
    fakeMacHelper();

    expect(app(Dashboard::class)->summary()['weather_error'])->toBe("I couldn't find the zip code 43081.");
});

it('groups the schedule into Eastern days, including empty ones', function () {
    fakeWeather();
    $this->travelTo(CarbonImmutable::parse('2026-10-09 15:00', 'America/New_York'));
    $eastern = CarbonImmutable::parse('2026-10-09', 'America/New_York');
    fakeMacHelper(events: [
        sharedEvent('Dentist', $eastern->setTime(17, 0)),
        sharedEvent('Picture day', $eastern, allDay: true),
        sharedEvent('Late soccer', $eastern->setTime(23, 30), end: $eastern->addDay()->setTime(1, 0)),
        sharedEvent('Trip', $eastern->addDay(), allDay: true, end: $eastern->addDays(2)),
        sharedEvent('Too far', $eastern->addDays(3)->setTime(9, 0)),
    ]);

    $schedule = app(Dashboard::class)->summary()['schedule'];

    expect(array_column($schedule, 'date'))->toBe(['2026-10-09', '2026-10-10', '2026-10-11'])
        ->and(array_column($schedule, 'label'))->toBe(['Today', 'Tomorrow', 'Sunday, October 11'])
        ->and(array_column($schedule[0]['events'], 'title'))->toBe(['Picture day', 'Dentist', 'Late soccer'])
        ->and(array_column($schedule[1]['events'], 'title'))->toBe(['Trip', 'Late soccer'])
        ->and(array_column($schedule[2]['events'], 'title'))->toBe(['Trip'])
        ->and($schedule[0]['events'][1])->toMatchArray([
            'calendar' => 'Family',
            'all_day' => false,
            'start' => '2026-10-09T21:00:00+00:00',
            'end' => '2026-10-09T22:00:00+00:00',
        ])
        ->and($schedule[0]['events'][0])->toMatchArray(['all_day' => true, 'start' => '2026-10-09']);
});

it('adds drive estimates to the remaining timed events with a location', function () {
    fakeWeather();
    $this->travelTo(CarbonImmutable::parse('2026-10-09 15:00', 'America/New_York'));
    $eastern = CarbonImmutable::parse('2026-10-09', 'America/New_York');
    $helper = fakeMacHelper(events: [
        sharedEvent('Earlier', $eastern->setTime(9, 0), '1 Main St'),
        sharedEvent('Dentist', $eastern->setTime(17, 0), '12 High St, Columbus, OH'),
        sharedEvent('No place', $eastern->setTime(18, 0)),
        sharedEvent('Fair', $eastern, '4 Fair Ave', allDay: true),
        sharedEvent('Tomorrow game', $eastern->addDay()->setTime(10, 0), '9 Field Rd'),
    ]);
    HouseholdSetting::put(HouseholdSetting::HomeAddress, '123 Main St, Westerville, OH');

    $summary = app(Dashboard::class)->summary();
    $events = collect($summary['schedule'][0]['events'])->keyBy('title');

    expect($summary['home_address_set'])->toBeTrue()
        ->and($summary['drive_error'])->toBeNull()
        ->and($events['Dentist']['drive'])->toBe([
            'minutes' => 22,
            'miles' => 11.4,
            'leave_by' => '2026-10-09T20:38:00+00:00',
            'traffic_aware' => true,
            'maps_url' => 'https://maps.apple.com/?saddr=123%20Main%20St%2C%20Westerville%2C%20OH&daddr=12%20High%20St%2C%20Columbus%2C%20OH&dirflg=d',
        ])
        ->and($events['Earlier']['drive'])->toBeNull()
        ->and($events['No place']['drive'])->toBeNull()
        ->and($events['Fair']['drive'])->toBeNull()
        ->and($summary['schedule'][1]['events'][0]['drive'])->toBeNull();

    $drives = array_filter($helper->runs, fn (array $run): bool => $run[0] === 'drive');
    expect($drives)->toHaveCount(1);

    app(Dashboard::class)->summary();

    expect(array_filter($helper->runs, fn (array $run): bool => $run[0] === 'drive'))->toHaveCount(1);
});

it('skips drives until a home address is set', function () {
    fakeWeather();
    $this->travelTo(CarbonImmutable::parse('2026-10-09 15:00', 'America/New_York'));
    $helper = fakeMacHelper(events: [sharedEvent('Dentist', CarbonImmutable::parse('2026-10-09 17:00', 'America/New_York'), '12 High St')]);

    $summary = app(Dashboard::class)->summary();

    expect($summary['home_address_set'])->toBeFalse()
        ->and($summary['drive_error'])->toBeNull()
        ->and($summary['schedule'][0]['events'][0]['drive'])->toBeNull()
        ->and(array_filter($helper->runs, fn (array $run): bool => $run[0] === 'drive'))->toBeEmpty();
});

it('keeps the schedule when drive times fail', function () {
    fakeWeather();
    $this->travelTo(CarbonImmutable::parse('2026-10-09 15:00', 'America/New_York'));
    fakeMacHelper(events: [sharedEvent('Dentist', CarbonImmutable::parse('2026-10-09 17:00', 'America/New_York'), '12 High St')]);
    HouseholdSetting::put(HouseholdSetting::HomeAddress, '123 Main St');
    $this->mock(MacDirections::class)->shouldReceive('drive')->once()->andThrow(new MacHelperException('Apple Maps had no route.'));

    $summary = app(Dashboard::class)->summary();

    expect($summary['drive_error'])->toBe('Apple Maps had no route.')
        ->and($summary['schedule_error'])->toBeNull()
        ->and($summary['schedule'][0]['events'][0])->toMatchArray(['title' => 'Dentist', 'drive' => null]);
});

it('keeps the weather when the helper is not installed', function () {
    fakeWeather();
    config(['mac_helper.app_path' => '/nonexistent/Helper.app']);

    $summary = app(Dashboard::class)->summary();

    expect($summary['schedule_error'])->toContain('mac-helper:install')
        ->and($summary['schedule'])->toHaveCount(3)
        ->and($summary['weather'])->not->toBeNull();
});

it('explains when no calendars are shared', function () {
    fakeWeather();
    fakeMacHelper();
    SharedCalendar::query()->delete();

    $summary = app(Dashboard::class)->summary();

    expect($summary['schedule_error'])->toContain('No calendars are shared')
        ->and($summary['schedule'])->toHaveCount(3);
});

/**
 * The mobile app's tests read this same file, so the two apps cannot drift apart silently.
 * Regenerate it with UPDATE_DASHBOARD_FIXTURE=1 php artisan test --compact --filter=DashboardContract.
 */
it('matches the payload fixture the mobile app is tested against', function () {
    HouseholdSetting::put(HouseholdSetting::HomeAddress, '1 Main St, Westerville, OH');

    $eastern = CarbonImmutable::parse('2026-10-09', 'America/New_York');
    $this->travelTo($eastern->setTime(15, 0));

    fakeWeather();
    fakeMacHelper(events: [
        sharedEvent('Picture day', $eastern, allDay: true),
        sharedEvent('Soccer practice', $eastern->setTime(17, 0), 'Alum Creek Park, Lewis Center, OH', end: $eastern->setTime(18, 30)),
        sharedEvent('Dentist', $eastern->addDay()->setTime(9, 0)),
        sharedEvent('Fall trip', $eastern->addDay(), allDay: true, end: $eastern->addDays(2)),
    ]);

    $summary = json_decode(json_encode(app(Dashboard::class)->summary()), true);
    $path = base_path('mobile/tests/Fixtures/dashboard-summary.json');

    if (getenv('UPDATE_DASHBOARD_FIXTURE') || ! file_exists($path)) {
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    expect($summary)->toBe(json_decode(file_get_contents($path), true));
});
