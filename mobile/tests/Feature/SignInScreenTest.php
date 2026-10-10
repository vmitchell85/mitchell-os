<?php

use App\NativeComponents\Auth\SignIn;
use App\Services\Api\Session;
use App\Services\Api\TokenStorageException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    $this->session = Mockery::mock(Session::class);
    $this->session->shouldReceive('serverUrl')->andReturn('http://10.0.0.5:8000');
    $this->session->shouldReceive('setServerUrl')->byDefault();
    $this->app->instance(Session::class, $this->session);
});

it('prefills the saved server address', function () {
    Native::test(SignIn::class)->assertSet('serverUrl', 'http://10.0.0.5:8000');
});

it('stores the token and moves to today on success', function () {
    Http::fake(['*' => Http::response(['token' => '1|abc', 'user' => ['id' => 1]])]);
    $this->session->shouldReceive('signIn')->once()->with('1|abc');

    Native::test(SignIn::class)
        ->set('email', 'vince@example.com')
        ->set('password', 'secret')
        ->call('signIn')
        ->assertReplacedWith('/today');

    Http::assertSent(fn (Request $request) => $request->url() === 'http://10.0.0.5:8000/api/v1/tokens'
        && $request['email'] === 'vince@example.com'
        && $request['device_name'] !== ''
        && ! isset($request['code']));
});

it('sends the two-factor code once it is entered', function () {
    Http::fake(['*' => Http::response(['token' => '1|abc'])]);
    $this->session->shouldReceive('signIn')->once();

    Native::test(SignIn::class)
        ->set('email', 'vince@example.com')
        ->set('password', 'secret')
        ->set('code', '123456')
        ->call('signIn');

    Http::assertSent(fn (Request $request) => $request['code'] === '123456');
});

it('shows the error and reveals the code field when the credentials are rejected', function () {
    Http::fake(['*' => Http::response(['errors' => ['email' => ['These credentials do not match our records.']]], 422)]);
    $this->session->shouldReceive('signIn')->never();

    Native::test(SignIn::class)
        ->set('email', 'vince@example.com')
        ->set('password', 'wrong')
        ->call('signIn')
        ->assertSet('needsCode', true)
        ->assertSet('loading', false)
        ->assertSee('These credentials do not match our records.')
        ->assertSee('Two-factor code');
});

it('asks for a code when the API says two-factor is required', function () {
    Http::fake(['*' => Http::response(['two_factor_required' => true], 422)]);

    Native::test(SignIn::class)
        ->set('email', 'vince@example.com')
        ->set('password', 'secret')
        ->call('signIn')
        ->assertSet('needsCode', true)
        ->assertSee('Enter the code from your authenticator app.');
});

it('explains when the server cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('refused'));

    Native::test(SignIn::class)
        ->set('email', 'vince@example.com')
        ->set('password', 'secret')
        ->call('signIn')
        ->assertSet('loading', false)
        ->assertSee('Could not reach the server.');
});

it('requires the fields before calling the API', function () {
    Http::fake();

    Native::test(SignIn::class)
        ->call('signIn')
        ->assertSee('Enter the server address, your email and your password.');

    Http::assertNothingSent();
});

it('says so when the token cannot be saved on the device', function () {
    Http::fake(['*' => Http::response(['token' => '1|abc'])]);
    $this->session->shouldReceive('signIn')->once()->andThrow(new TokenStorageException);

    Native::test(SignIn::class)
        ->set('email', 'vince@example.com')
        ->set('password', 'secret')
        ->call('signIn')
        ->assertSee('Could not save your sign-in on this device.');
});
