<?php

use App\NativeComponents\Settings\SettingsScreen;
use App\Services\Api\Session;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    $this->session = Mockery::mock(Session::class);
    $this->session->shouldReceive('serverUrl')->andReturn('http://10.0.0.5:8000');
    $this->session->shouldReceive('token')->andReturn('secret-token');
    $this->app->instance(Session::class, $this->session);
});

it('shows the signed-in user and a back chevron', function () {
    Http::fake(['*/user' => Http::response(['data' => ['name' => 'Vince Mitchell', 'email' => 'vince@example.com']])]);

    Native::visit('/settings')
        ->assertNavTitle('Settings')
        ->assertSee('Vince Mitchell')
        ->assertSee('vince@example.com')
        ->assertTabBarHidden();
});

it('saves the server address', function () {
    Http::fake(['*/user' => Http::response(['data' => ['name' => 'V', 'email' => 'v@example.com']])]);
    $this->session->shouldReceive('setServerUrl')->once()->with('http://192.168.1.20:8000');

    Native::test(SettingsScreen::class)
        ->set('serverUrl', 'http://192.168.1.20:8000')
        ->call('saveServerUrl')
        ->assertSee('Server address saved.');
});

it('reports the result of a Skylight sync', function () {
    Http::fake([
        '*/user' => Http::response(['data' => ['name' => 'V', 'email' => 'v@example.com']]),
        '*/skylight/sync' => Http::response(['message' => 'Synced 3 lists.']),
    ]);

    Native::test(SettingsScreen::class)
        ->call('syncWithSkylight')
        ->assertSet('syncing', false)
        ->assertSet('syncFailed', false)
        ->assertSee('Synced 3 lists.');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/api/v1/skylight/sync'));
});

it('shows a failed Skylight sync as an error', function () {
    Http::fake([
        '*/user' => Http::response(['data' => ['name' => 'V', 'email' => 'v@example.com']]),
        '*/skylight/sync' => Http::response(['message' => 'Skylight is unreachable.'], 502),
    ]);

    Native::test(SettingsScreen::class)
        ->call('syncWithSkylight')
        ->assertSet('syncFailed', true)
        ->assertSee('Skylight is unreachable.');
});

it('revokes the token, signs out and returns to sign in', function () {
    Http::fake([
        '*/user' => Http::response(['data' => ['name' => 'V', 'email' => 'v@example.com']]),
        '*/tokens/current' => Http::response(null, 204),
    ]);
    $this->session->shouldReceive('signOut')->once();

    Native::test(SettingsScreen::class)
        ->call('signOut')
        ->assertReplacedWith('/sign-in');

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/api/v1/tokens/current'));
});

it('still signs out on this device when the server cannot be reached', function () {
    Http::fake([
        '*/user' => Http::response(['data' => ['name' => 'V', 'email' => 'v@example.com']]),
        '*/tokens/current' => fn () => throw new ConnectionException('refused'),
    ]);
    $this->session->shouldReceive('signOut')->once();

    Native::test(SettingsScreen::class)->call('signOut')->assertReplacedWith('/sign-in');
});

it('sends an expired session back to sign in', function () {
    Http::fake(['*/user' => Http::response(['message' => 'Unauthenticated.'], 401)]);
    $this->session->shouldReceive('signOut')->once();

    Native::test(SettingsScreen::class)->assertReplacedWith('/sign-in');
});
