<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Sanctum\PersonalAccessToken;

function signInPayload(User $user, array $overrides = []): array
{
    return array_merge([
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'iPhone',
    ], $overrides);
}

it('issues a token and the user for valid credentials', function () {
    $user = User::factory()->create(['name' => 'Vince']);

    $response = $this->postJson(route('api.v1.tokens.store'), signInPayload($user))
        ->assertOk()
        ->assertJsonPath('user.name', 'Vince');

    $this->withToken($response->json('token'))->getJson(route('api.v1.user'))->assertOk();
    expect($user->tokens()->first()->name)->toBe('iPhone');
});

it('rejects a wrong password or unknown email with the same generic error', function () {
    $user = User::factory()->create();

    $wrongPassword = $this->postJson(route('api.v1.tokens.store'), signInPayload($user, ['password' => 'nope']))
        ->assertUnprocessable();
    $unknown = $this->postJson(route('api.v1.tokens.store'), signInPayload($user, ['email' => 'nobody@example.com']))
        ->assertUnprocessable();

    expect($wrongPassword->json('errors'))->toBe($unknown->json('errors'));
    expect(PersonalAccessToken::count())->toBe(0);
});

it('rejects an unverified email', function () {
    $user = User::factory()->unverified()->create();

    $this->postJson(route('api.v1.tokens.store'), signInPayload($user))->assertUnprocessable();
    expect(PersonalAccessToken::count())->toBe(0);
});

it('requires a two-factor code when two-factor authentication is on', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->postJson(route('api.v1.tokens.store'), signInPayload($user))
        ->assertUnprocessable()
        ->assertJsonPath('two_factor_required', true);
    expect(PersonalAccessToken::count())->toBe(0);
});

it('does not reveal that two-factor authentication is on when the password is wrong', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->postJson(route('api.v1.tokens.store'), signInPayload($user, ['password' => 'nope']))
        ->assertUnprocessable()
        ->assertJsonMissingPath('two_factor_required');
    $this->postJson(route('api.v1.tokens.store'), signInPayload($user, ['code' => '000000', 'password' => 'nope']))
        ->assertUnprocessable()
        ->assertJsonMissingPath('two_factor_required');
});

it('does not flag two-factor as required when the code is wrong', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->mock(TwoFactorAuthenticationProvider::class)->shouldReceive('verify')->andReturn(false);

    $this->postJson(route('api.v1.tokens.store'), signInPayload($user, ['code' => '000000']))
        ->assertUnprocessable()
        ->assertJsonMissingPath('two_factor_required');
});

it('accepts a valid two-factor code and rejects an invalid one', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->mock(TwoFactorAuthenticationProvider::class)
        ->shouldReceive('verify')
        ->andReturnUsing(fn (string $secret, string $code): bool => $code === '123456');

    $this->postJson(route('api.v1.tokens.store'), signInPayload($user, ['code' => '000000']))->assertUnprocessable();
    $this->postJson(route('api.v1.tokens.store'), signInPayload($user, ['code' => '123456']))
        ->assertOk()
        ->assertJsonStructure(['token', 'user']);
});

it('accepts a recovery code once', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->postJson(route('api.v1.tokens.store'), signInPayload($user, ['recovery_code' => 'wrong']))->assertUnprocessable();
    $this->postJson(route('api.v1.tokens.store'), signInPayload($user, ['recovery_code' => 'recovery-code-1']))->assertOk();
    $this->postJson(route('api.v1.tokens.store'), signInPayload($user, ['recovery_code' => 'recovery-code-1']))->assertUnprocessable();
});

it('rate limits sign-in attempts per email and IP', function () {
    $user = User::factory()->create();
    RateLimiter::clear('api-login');

    foreach (range(1, 5) as $attempt) {
        $this->postJson(route('api.v1.tokens.store'), signInPayload($user, ['password' => 'nope']))->assertUnprocessable();
    }

    $this->postJson(route('api.v1.tokens.store'), signInPayload($user))->assertTooManyRequests();
});

it('revokes only the current token', function () {
    $user = User::factory()->create();
    $current = $user->createToken('iPhone');
    $user->createToken('iPad');

    $this->withToken($current->plainTextToken)
        ->deleteJson(route('api.v1.tokens.destroy'))
        ->assertNoContent();

    expect($user->tokens()->pluck('name')->all())->toBe(['iPad']);
});

it('requires authentication to revoke a token', function () {
    $this->deleteJson(route('api.v1.tokens.destroy'))->assertUnauthorized();
});
