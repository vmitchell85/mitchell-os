<?php

use App\Services\Api\Session;
use App\Services\Api\TokenStorageException;
use Native\Mobile\SecureStorage;

beforeEach(function () {
    $this->store = [];

    $this->mock(SecureStorage::class, function ($mock) {
        $mock->shouldReceive('get')->andReturnUsing(fn (string $key) => $this->store[$key] ?? null);
        $mock->shouldReceive('set')->andReturnUsing(function (string $key, ?string $value) {
            $this->store[$key] = $value;

            return true;
        });
        $mock->shouldReceive('delete')->andReturnUsing(function (string $key) {
            unset($this->store[$key]);

            return true;
        });
    });
});

it('is signed out until a token is stored', function () {
    $session = app(Session::class);

    expect($session->isSignedIn())->toBeFalse();

    $session->signIn('abc123');

    expect($session->isSignedIn())->toBeTrue()
        ->and($session->token())->toBe('abc123')
        ->and($this->store[Session::TOKEN_KEY])->toBe('abc123');
});

it('forgets the token on sign out', function () {
    $session = app(Session::class);
    $session->signIn('abc123');

    $session->signOut();

    expect($session->isSignedIn())->toBeFalse()->and($session->token())->toBeNull();
});

it('falls back to the configured server URL', function () {
    config(['services.mitchell.url' => 'http://10.0.0.5:8000/']);

    expect(app(Session::class)->serverUrl())->toBe('http://10.0.0.5:8000');
});

it('saves a server URL, adding http and trimming slashes', function () {
    $session = app(Session::class);

    $session->setServerUrl(' 192.168.1.20:8000/ ');

    expect($session->serverUrl())->toBe('http://192.168.1.20:8000');
});

it('returns to the default when the server URL is cleared', function () {
    config(['services.mitchell.url' => 'http://10.0.0.5:8000']);
    $session = app(Session::class);
    $session->setServerUrl('http://example.test');

    $session->setServerUrl('  ');

    expect($session->serverUrl())->toBe('http://10.0.0.5:8000');
});

it('throws when secure storage cannot save the token', function () {
    $this->mock(SecureStorage::class, function ($mock) {
        $mock->shouldReceive('set')->andReturn(false);
    });

    app(Session::class)->signIn('abc123');
})->throws(TokenStorageException::class);
