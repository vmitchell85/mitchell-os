<?php

use App\Models\User;
use Livewire\Livewire;

it('asks for the password before showing the tokens page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('api-tokens.edit'))->assertRedirect(route('password.confirm'));
});

it('shows the tokens page once the password is confirmed', function () {
    $user = User::factory()->create();
    $user->createToken('My phone');

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('api-tokens.edit'))
        ->assertOk()
        ->assertSee('My phone');
});

it('creates a token and shows it once', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test('pages::settings.api-tokens')
        ->set('tokenName', 'My phone')
        ->call('createToken')
        ->assertHasNoErrors();

    $token = $user->tokens()->sole();
    expect($token->name)->toBe('My phone');
    expect($component->get('plainTextToken'))->toStartWith("{$token->id}|");
});

it('requires a token name', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::settings.api-tokens')
        ->call('createToken')
        ->assertHasErrors(['tokenName' => 'required']);
});

it('revokes only the user\'s own tokens', function () {
    $user = User::factory()->create();
    $mine = $user->createToken('Mine')->accessToken;
    $theirs = User::factory()->create()->createToken('Theirs')->accessToken;

    Livewire::actingAs($user)
        ->test('pages::settings.api-tokens')
        ->call('revoke', $mine->id)
        ->call('revoke', $theirs->id);

    expect($user->tokens()->count())->toBe(0);
    expect($theirs->fresh())->not->toBeNull();
});
