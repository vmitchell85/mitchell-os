<?php

use App\Services\Api\Session;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Testing\Native;

function dashboardSummary(array $overrides = []): array
{
    return array_replace([
        'generated_at' => '2026-10-10T14:00:00+00:00',
        'weather' => [
            'location' => 'Westerville, OH 43081',
            'temperature_f' => 61,
            'conditions' => 'Partly Cloudy',
            'icon' => 'partly-cloudy',
            'high_f' => 68,
            'low_f' => 49,
            'precip_chance' => 20,
            'hourly' => [
                ['time' => '2026-10-10T19:00:00+00:00', 'temperature_f' => 63, 'conditions' => 'Sunny', 'icon' => 'sun', 'precip_chance' => 0],
            ],
            'alerts' => [['headline' => 'Frost Advisory', 'severity' => 'Minor']],
        ],
        'weather_error' => null,
        'schedule' => [
            ['date' => '2026-10-10', 'label' => 'Today', 'events' => [[
                'title' => 'Soccer practice',
                'calendar' => 'Family',
                'color' => '#1BADF8',
                'on_color' => '#111827',
                'when' => '',
                'all_day' => false,
                'start' => '2026-10-10T20:30:00+00:00',
                'end' => '2026-10-10T21:30:00+00:00',
                'location' => 'Alum Creek Park',
                'drive' => ['minutes' => 22, 'miles' => 9.4, 'leave_by' => '2026-10-10T20:08:00+00:00', 'traffic_aware' => true, 'maps_url' => 'https://maps.apple.com/?daddr=Alum+Creek+Park'],
            ], [
                'title' => 'Picture day',
                'calendar' => 'School',
                'color' => '#B3261E',
                'on_color' => '#FFFFFF',
                'when' => 'All day',
                'all_day' => true,
                'start' => '2026-10-10T04:00:00+00:00',
                'end' => '2026-10-11T04:00:00+00:00',
                'location' => null,
                'drive' => null,
            ]]],
            ['date' => '2026-10-11', 'label' => 'Tomorrow', 'events' => []],
        ],
        'schedule_error' => null,
        'drive_error' => null,
        'home_address_set' => true,
    ], $overrides);
}

beforeEach(function () {
    $this->mock(Session::class)
        ->shouldReceive('signOut')
        ->shouldReceive('isSignedIn')->andReturn(true)
        ->shouldReceive('serverUrl')->andReturn('http://10.0.0.5:8000')
        ->shouldReceive('token')->andReturn('secret-token');
});

it('shows the weather, schedule and drive time in Eastern time', function () {
    Http::fake(['*/dashboard' => Http::response(['data' => dashboardSummary()])]);

    Native::visit('/today')
        ->assertNavTitle('Today')
        ->assertSee('61°')
        ->assertSee('Partly Cloudy')
        ->assertSee('High 68° · Low 49° · 20% chance of rain')
        ->assertSee('Frost Advisory')
        ->assertSee('3:00 PM')
        ->assertSee('Today')
        ->assertSee('Soccer practice')
        ->assertSee('4:30 PM – 5:30 PM')
        ->assertSee('Family calendar')
        ->assertSee('School calendar')
        ->assertSee('Picture day')
        ->assertSee('Alum Creek Park')
        ->assertSee('22 min · leave by 4:08 PM')
        ->assertSee('Tomorrow')
        ->assertSee('Nothing scheduled.');

    Http::assertSent(fn ($request) => $request->url() === 'http://10.0.0.5:8000/api/v1/dashboard'
        && $request->hasHeader('Authorization', 'Bearer secret-token'));
});

it('opens the drive directions in the browser', function () {
    Http::fake(['*/dashboard' => Http::response(['data' => dashboardSummary()])]);
    Browser::shouldReceive('open')->once()->with('https://maps.apple.com/?daddr=Alum+Creek+Park');

    Native::visit('/today')->call('openDrive', 0, 0);
});

it('refreshes when pulled down and when the app resumes', function () {
    Http::fake(['*/dashboard' => Http::response(['data' => dashboardSummary()])]);

    Native::visit('/today')->call('refresh')->call('onResume');

    Http::assertSentCount(3);
});

it('explains a missing home address and a weather problem', function () {
    Http::fake(['*/dashboard' => Http::response(['data' => dashboardSummary([
        'weather' => null,
        'weather_error' => 'Weather is unavailable right now.',
        'home_address_set' => false,
    ])])]);

    Native::visit('/today')
        ->assertSee('Weather is unavailable right now.')
        ->assertSee('Set your home address');
});

it('shows the drive error when the helper fails', function () {
    Http::fake(['*/dashboard' => Http::response(['data' => dashboardSummary(['drive_error' => 'Drive times are unavailable.'])])]);

    Native::visit('/today')->assertSee('Drive times are unavailable.');
});

it('shows an error when the server fails', function () {
    Http::fake(['*/dashboard' => Http::response([], 500)]);

    Native::visit('/today')->assertSee('Could not load today.');
});

it('sends a rejected token back to sign in', function () {
    Http::fake(['*/dashboard' => Http::response([], 401)]);

    Native::visit('/today')->assertReplacedWith('/sign-in');
});

it('reads the real server payload', function () {
    $summary = json_decode(file_get_contents(__DIR__.'/../Fixtures/dashboard-summary.json'), true);
    Http::fake(['*/dashboard' => Http::response(['data' => $summary])]);
    Browser::shouldReceive('open')->once()->with($summary['schedule'][0]['events'][1]['drive']['maps_url']);

    Native::visit('/today')
        ->assertSee('64°')
        ->assertSee('Light Rain')
        ->assertSee('High 72° · Low 51° · 40% chance of rain')
        ->assertSee('Frost Advisory issued for Franklin County')
        ->assertSee('Today')
        ->assertDontSee('All day')
        ->assertSee('Picture day')
        ->assertSee('5:00 PM – 6:30 PM')
        ->assertSee('Alum Creek Park, Lewis Center, OH')
        ->assertSee('22 min · leave by 4:38 PM')
        ->assertSee('9:00 AM – 10:00 AM')
        ->assertSee('Sunday, October 11')
        ->call('openDrive', 0, 1);
});

it('splits all-day events into colored badges and timed events into rows', function () {
    Http::fake(['*/dashboard' => Http::response(['data' => dashboardSummary()])]);
    Browser::shouldReceive('open')->once()->with('https://maps.apple.com/?daddr=Alum+Creek+Park');

    $screen = Native::visit('/today')
        ->assertSee('Picture day, School calendar, all day')
        ->call('openDrive', 0, 0);

    expect(json_encode($screen->tree()))->toContain('B3261E', 'FFFFFF', '1BADF8');
});
