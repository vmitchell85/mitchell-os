<?php

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = Sanctum::actingAs(User::factory()->create());
});

it('lists and searches recipes', function () {
    Recipe::factory()->create(['name' => 'Mac and cheese']);
    Recipe::factory()->create(['name' => 'Chicken tacos']);

    $this->getJson(route('api.v1.recipes.index'))->assertOk()->assertJsonPath('data.0.name', 'Chicken tacos');
    $this->getJson(route('api.v1.recipes.index', ['search' => 'mac']))->assertJsonCount(1, 'data');
});

it('adds, updates and deletes a recipe', function () {
    $id = $this->postJson(route('api.v1.recipes.store'), [
        'name' => 'Beef chili', 'ingredients' => ['1 lb ground beef', ''], 'steps' => ['Simmer.'], 'total_minutes' => 45,
    ])->assertCreated()
        ->assertJsonPath('data.ingredients', ['1 lb ground beef'])
        ->assertJsonPath('data.added_by', $this->user->id)
        ->json('data.id');

    $this->patchJson(route('api.v1.recipes.update', $id), ['servings' => '6'])
        ->assertOk()
        ->assertJsonPath('data.servings', '6')
        ->assertJsonPath('data.name', 'Beef chili');

    $this->deleteJson(route('api.v1.recipes.destroy', $id))->assertNoContent();
    expect(Recipe::count())->toBe(0);
});

it('reads a recipe from a link without saving it', function () {
    Http::fake(['https://93.184.215.14/*' => Http::response('<p>Lasagna</p>')]);
    fakeClaudeProcess(claudeStructuredResult([
        'name' => 'Classic lasagna', 'ingredients' => ['1 lb ground beef'], 'steps' => ['Bake.'], 'servings' => '', 'total_minutes' => 0, 'notes' => '',
    ]));

    $this->postJson(route('api.v1.recipes.import'), ['url' => 'https://93.184.215.14/lasagna'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Classic lasagna')
        ->assertJsonPath('data.total_minutes', null);

    expect(Recipe::count())->toBe(0);
});

it('explains a link it cannot import', function () {
    $this->postJson(route('api.v1.recipes.import'), ['url' => 'http://127.0.0.1/admin'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'That page isn\'t on the public internet.');
});
