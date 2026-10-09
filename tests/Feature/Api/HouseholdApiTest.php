<?php

use App\Models\HouseholdSetting;
use App\Models\User;
use App\Services\Mcp\McpException;
use App\Services\Skylight\SkylightClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakeMcpClient;
use Tests\Fakes\FakeSkylight;

it('authenticates with a token from the settings page', function () {
    $user = User::factory()->create(['name' => 'Vince']);
    $token = $user->createToken('Phone')->plainTextToken;

    $this->withToken($token)->getJson(route('api.v1.user'))
        ->assertOk()
        ->assertJsonPath('data.name', 'Vince');
});

it('rejects a revoked token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('Phone');
    $token->accessToken->delete();

    $this->withToken($token->plainTextToken)->getJson(route('api.v1.user'))->assertUnauthorized();
});

it('requires a verified email', function () {
    Sanctum::actingAs(User::factory()->unverified()->create());

    $this->getJson(route('api.v1.user'))->assertForbidden();
});

it('reads and updates the household food rules', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->putJson(route('api.v1.food-rules.update'), ['food_rules' => ' No seafood. '])
        ->assertOk()
        ->assertExactJson(['data' => ['food_rules' => 'No seafood.']]);

    expect(HouseholdSetting::get(HouseholdSetting::FoodRules))->toBe('No seafood.');

    $this->putJson(route('api.v1.food-rules.update'), ['food_rules' => null])->assertOk();

    $this->getJson(route('api.v1.food-rules.show'))->assertExactJson(['data' => ['food_rules' => null]]);
});

it('syncs with skylight', function () {
    Sanctum::actingAs(User::factory()->create());
    $skylight = (new FakeSkylight)->addList('9002', 'Groceries');
    $skylight->addItem('9002', 'Milk');
    app()->instance(SkylightClient::class, new SkylightClient($skylight));

    $this->postJson(route('api.v1.skylight.sync'))
        ->assertOk()
        ->assertJsonPath('message', fn (string $message) => str_contains($message, '1 change from Skylight.'));
});

it('reports a sync that is already running as a conflict', function () {
    Sanctum::actingAs(User::factory()->create());
    Cache::lock('skylight-sync', 300)->get();

    $this->postJson(route('api.v1.skylight.sync'))->assertConflict();
});

it('reports skylight being unreachable as a bad gateway', function () {
    Exceptions::fake();
    Sanctum::actingAs(User::factory()->create());
    app()->instance(SkylightClient::class, new SkylightClient(new FakeMcpClient([
        'skylight_list_lists' => fn () => throw new McpException('The MCP server exited during [initialize].'),
    ])));

    $this->postJson(route('api.v1.skylight.sync'))
        ->assertStatus(502)
        ->assertJsonPath('message', 'The MCP server exited during [initialize].');
});
