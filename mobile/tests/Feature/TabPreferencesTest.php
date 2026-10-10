<?php

use App\NativeComponents\Auth\SignIn;
use App\NativeComponents\Settings\SettingsScreen;
use App\Services\Api\Session;
use App\Services\Tabs\AppTab;
use App\Services\Tabs\TabPreferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    $this->tabs = app(TabPreferences::class);
});

it('enables every tab by default', function () {
    expect($this->tabs->enabled())->toBe(AppTab::cases())
        ->and($this->tabs->homeRoute())->toBe('/today');
});

it('saves a choice and keeps the standard order', function () {
    expect($this->tabs->set(AppTab::Chat, false))->toBeTrue()
        ->and($this->tabs->set(AppTab::Lists, false))->toBeTrue();

    expect($this->tabs->enabled())->toBe([AppTab::Today, AppTab::Meals, AppTab::Recipes, AppTab::Household]);

    $this->tabs->set(AppTab::Chat, true);

    expect($this->tabs->enabled())->toBe([AppTab::Today, AppTab::Chat, AppTab::Meals, AppTab::Recipes, AppTab::Household])
        ->and(app(TabPreferences::class)->isEnabled(AppTab::Lists))->toBeFalse();
});

it('turns a newly added tab on for a device that saved its choice before', function () {
    DB::table('device_settings')->insert(['key' => TabPreferences::SETTING_KEY, 'value' => json_encode(['chat', 'meals', 'household'])]);

    expect(app(TabPreferences::class)->enabled())->toBe([AppTab::Today, AppTab::Chat, AppTab::Meals, AppTab::Household]);

    app(TabPreferences::class)->set(AppTab::Today, false);

    expect(app(TabPreferences::class)->enabled())->toBe([AppTab::Chat, AppTab::Meals, AppTab::Household]);
});

it('refuses to turn off the last tab', function () {
    foreach ([AppTab::Today, AppTab::Chat, AppTab::Meals, AppTab::Lists, AppTab::Recipes] as $tab) {
        $this->tabs->set($tab, false);
    }

    expect($this->tabs->set(AppTab::Household, false))->toBeFalse()
        ->and($this->tabs->enabled())->toBe([AppTab::Household]);
});

it('uses the first enabled tab as home', function () {
    $this->tabs->set(AppTab::Today, false);
    $this->tabs->set(AppTab::Chat, false);
    $this->tabs->set(AppTab::Meals, false);

    expect($this->tabs->homeRoute())->toBe('/lists');
});

it('shows only the enabled tabs in the bottom nav', function () {
    Http::fake(['*' => Http::response(['data' => []])]);
    $this->tabs->set(AppTab::Chat, false);
    $this->tabs->set(AppTab::Recipes, false);

    Native::visit('/meals')
        ->assertHasTab('Meals')
        ->assertHasTab('Lists')
        ->assertHasTab('Household')
        ->assertDontSee('Recipes');
});

it('keeps working on a tab that was just hidden', function () {
    Http::fake(['*' => Http::response(['data' => []])]);
    $this->tabs->set(AppTab::Meals, false);

    Native::visit('/meals')->assertHasTabBar()->assertHasTab('Chat');
});

it('opens to the first enabled tab when signed in', function () {
    $this->mock(Session::class)->shouldReceive('isSignedIn')->andReturn(true);
    $this->tabs->set(AppTab::Today, false);
    $this->tabs->set(AppTab::Chat, false);

    Native::visit('/')->assertReplacedWith('/meals');
});

it('lists a toggle for each tab in settings and saves a flip', function () {
    $session = $this->mock(Session::class);
    $session->shouldReceive('serverUrl')->andReturn('http://10.0.0.5:8000');
    $session->shouldReceive('token')->andReturn('secret-token');
    Http::fake(['*/user' => Http::response(['data' => ['name' => 'V', 'email' => 'v@example.com']])]);

    Native::test(SettingsScreen::class)
        ->assertSee('Tabs')
        ->assertSee('Household')
        ->call('toggleTab', 'lists')
        ->assertSet('tabsMessage', '');

    expect($this->tabs->isEnabled(AppTab::Lists))->toBeFalse();
});

it('tells the user the last tab has to stay on', function () {
    $session = $this->mock(Session::class);
    $session->shouldReceive('serverUrl')->andReturn('http://10.0.0.5:8000');
    $session->shouldReceive('token')->andReturn('secret-token');
    Http::fake(['*/user' => Http::response(['data' => ['name' => 'V', 'email' => 'v@example.com']])]);

    foreach ([AppTab::Today, AppTab::Chat, AppTab::Meals, AppTab::Lists, AppTab::Recipes] as $tab) {
        $this->tabs->set($tab, false);
    }

    Native::test(SettingsScreen::class)
        ->call('toggleTab', 'household')
        ->assertSee('At least one tab has to stay on.');

    expect($this->tabs->enabled())->toBe([AppTab::Household]);
});

it('sends a new sign-in to the first enabled tab', function () {
    $this->tabs->set(AppTab::Today, false);
    $this->tabs->set(AppTab::Chat, false);
    $session = $this->mock(Session::class);
    $session->shouldReceive('serverUrl')->andReturn('http://10.0.0.5:8000');
    $session->shouldReceive('setServerUrl');
    $session->shouldReceive('signIn')->once();
    Http::fake(['*/tokens' => Http::response(['token' => 'abc'])]);

    Native::test(SignIn::class)
        ->set('email', 'v@example.com')
        ->set('password', 'secret')
        ->call('signIn')
        ->assertReplacedWith('/meals');
});
