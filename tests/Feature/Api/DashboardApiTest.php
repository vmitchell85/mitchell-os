<?php

use App\Models\HouseholdSetting;
use App\Models\User;
use App\Services\Dashboard\Dashboard;
use Laravel\Sanctum\Sanctum;

it('requires a token', function () {
    $this->getJson(route('api.v1.dashboard'))->assertUnauthorized();
});

it('returns the dashboard summary', function () {
    Sanctum::actingAs(User::factory()->create());
    $summary = [
        'generated_at' => '2026-10-09T19:00:00+00:00',
        'weather' => null,
        'weather_error' => 'Offline.',
        'schedule' => [],
        'schedule_error' => null,
        'drive_error' => null,
        'home_address_set' => false,
    ];
    $this->mock(Dashboard::class)->shouldReceive('summary')->once()->andReturn($summary);

    $this->getJson(route('api.v1.dashboard'))->assertOk()->assertExactJson(['data' => $summary]);
});

it('reads and updates where the household lives', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson(route('api.v1.household-settings.show'))
        ->assertExactJson(['data' => ['weather_zip' => '43081', 'home_address' => null]]);

    $this->putJson(route('api.v1.household-settings.update'), ['weather_zip' => '43215', 'home_address' => ' 1 Main St '])
        ->assertOk()
        ->assertExactJson(['data' => ['weather_zip' => '43215', 'home_address' => '1 Main St']]);

    $this->putJson(route('api.v1.household-settings.update'), ['weather_zip' => '43215', 'home_address' => null])->assertOk();

    expect(HouseholdSetting::homeAddress())->toBeNull();
});

it('rejects a zip code that is not five digits', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->putJson(route('api.v1.household-settings.update'), ['weather_zip' => 'abc', 'home_address' => null])
        ->assertJsonValidationErrors('weather_zip');
});
