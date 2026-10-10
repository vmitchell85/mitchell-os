<?php

use App\NativeComponents\Auth\SignIn;
use App\NativeComponents\Today\TodayScreen;
use App\Services\Api\Session;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

it('sends a signed-out user to sign in', function () {
    $this->mock(Session::class)
        ->shouldReceive('isSignedIn')->andReturn(false)
        ->shouldReceive('serverUrl')->andReturn('http://10.0.0.5:8000');

    Native::visit('/')
        ->assertReplacedWith('/sign-in')
        ->follow()
        ->assertScreen(SignIn::class);
});

it('sends a signed-in user to today', function () {
    $this->mock(Session::class)->shouldReceive('isSignedIn')->andReturn(true);

    Native::visit('/')
        ->assertReplacedWith('/today')
        ->follow()
        ->assertScreen(TodayScreen::class);
});

it('shows the six tabs on every main screen', function (string $path, string $active) {
    Http::fake(['*' => Http::response(['data' => []])]);

    Native::visit($path)
        ->assertHasTabBar()
        ->assertHasTab('Today')
        ->assertHasTab('Chat')
        ->assertHasTab('Meals')
        ->assertHasTab('Lists')
        ->assertHasTab('Recipes')
        ->assertHasTab('Household')
        ->assertTabActive($active);
})->with([
    ['/today', 'Today'],
    ['/chat', 'Chat'],
    ['/meals', 'Meals'],
    ['/lists', 'Lists'],
    ['/recipes', 'Recipes'],
    ['/household', 'Household'],
]);

it('renders sign in and settings without tabs', function (string $path) {
    Native::visit($path)->assertTabBarHidden();
})->with(['/sign-in', '/settings']);
