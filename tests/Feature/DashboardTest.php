<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Dashboard\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function mockSummary(array $overrides = []): void
    {
        $this->mock(Dashboard::class, function (MockInterface $mock) use ($overrides) {
            $mock->shouldReceive('summary')->andReturn(array_merge([
                'generated_at' => '2026-10-10T14:00:00+00:00',
                'weather' => null,
                'weather_error' => null,
                'schedule' => [],
                'schedule_error' => null,
                'drive_error' => null,
                'home_address_set' => true,
            ], $overrides));
        });
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $this->mockSummary();

        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk();
    }

    public function test_it_shows_the_weather_in_eastern_time(): void
    {
        $this->mockSummary(['weather' => [
            'location' => 'Westerville, OH 43081', 'temperature_f' => 61, 'conditions' => 'Partly Cloudy',
            'icon' => 'partly-cloudy', 'high_f' => 70, 'low_f' => 48, 'precip_chance' => 20,
            'hourly' => [['time' => '2026-10-10T19:00:00+00:00', 'temperature_f' => 63, 'conditions' => 'Sunny', 'icon' => 'sun', 'precip_chance' => null]],
            'alerts' => [['headline' => 'Frost Advisory until 9 AM', 'severity' => 'Minor']],
        ]]);

        Livewire::actingAs(User::factory()->create())->test('pages::dashboard')
            ->assertSee('61°')
            ->assertSee('Partly Cloudy')
            ->assertSee('Westerville, OH 43081')
            ->assertSee('High 70°, low 48°')
            ->assertSee('Frost Advisory until 9 AM')
            ->assertSee('3 PM');
    }

    public function test_it_shows_a_friendly_message_when_the_weather_is_missing(): void
    {
        $this->mockSummary(['weather_error' => 'The weather service didn\'t respond.']);

        Livewire::actingAs(User::factory()->create())->test('pages::dashboard')
            ->assertSee('Weather isn\'t available right now.')
            ->assertSee('The weather service didn\'t respond.');
    }

    public function test_it_groups_events_by_day_with_a_drive_chip_and_empty_days(): void
    {
        $this->mockSummary(['schedule' => [
            ['date' => '2026-10-10', 'label' => 'Today', 'events' => [[
                'title' => 'Soccer practice', 'calendar' => 'Family', 'when' => '5:00 PM – 6:30 PM', 'all_day' => false,
                'start' => '2026-10-10T21:00:00+00:00', 'end' => '2026-10-10T22:30:00+00:00', 'location' => 'Alum Creek Park',
                'drive' => ['minutes' => 22, 'miles' => 9.1, 'leave_by' => '2026-10-10T20:38:00+00:00', 'traffic_aware' => true, 'maps_url' => 'https://maps.apple.com/?daddr=Alum+Creek+Park'],
            ]]],
            ['date' => '2026-10-11', 'label' => 'Tomorrow', 'events' => []],
        ]]);

        Livewire::actingAs(User::factory()->create())->test('pages::dashboard')
            ->assertSee('Soccer practice')
            ->assertSee('Alum Creek Park')
            ->assertSee('22 min · leave by 4:38 PM')
            ->assertSee('https://maps.apple.com/?daddr=Alum+Creek+Park', false)
            ->assertSee('Tomorrow')
            ->assertSee('Nothing scheduled.')
            ->assertDontSee('Set your home address');
    }

    public function test_it_hints_at_setting_a_home_address(): void
    {
        $this->mockSummary(['home_address_set' => false]);

        Livewire::actingAs(User::factory()->create())->test('pages::dashboard')
            ->assertSee('Set your home address')
            ->assertSee(route('household'), false);
    }

    public function test_it_shows_schedule_and_drive_errors(): void
    {
        $this->mockSummary(['schedule_error' => 'Calendar access is off.', 'drive_error' => 'The Mac helper isn\'t installed.']);

        Livewire::actingAs(User::factory()->create())->test('pages::dashboard')
            ->assertSee('Calendar access is off.')
            ->assertSee('The Mac helper isn\'t installed.');
    }
}
