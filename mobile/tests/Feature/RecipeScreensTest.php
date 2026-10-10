<?php

use App\NativeComponents\Recipes\RecipeForm;
use App\NativeComponents\Recipes\RecipeIndex;
use App\NativeComponents\Recipes\RecipeShow;
use App\Services\Api\Session;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;

const RECIPE_API = 'http://10.0.0.5:8000/api/v1';

beforeEach(function () {
    $this->session = Mockery::mock(Session::class);
    $this->session->shouldReceive('serverUrl')->andReturn('http://10.0.0.5:8000');
    $this->session->shouldReceive('token')->andReturn('secret-token');
    $this->session->shouldReceive('signOut')->byDefault();
    $this->app->instance(Session::class, $this->session);
});

/**
 * @return array<string, mixed>
 */
function recipe(array $overrides = []): array
{
    return [
        'id' => 7,
        'name' => 'Lemon Chicken',
        'ingredients' => ['2 chicken breasts', '1 lemon'],
        'steps' => ['Season the chicken.', 'Bake for 25 minutes.'],
        'servings' => '4',
        'total_minutes' => 35,
        'notes' => 'Great with rice.',
        'source_url' => 'https://example.com/lemon-chicken',
        ...$overrides,
    ];
}

it('lists recipes from the API', function () {
    Http::fake([RECIPE_API.'/recipes*' => Http::response(['data' => [recipe(), recipe(['id' => 8, 'name' => 'Chili'])]])]);

    Native::test(RecipeIndex::class)
        ->assertSee('Lemon Chicken')
        ->assertSee('Chili');
});

it('searches recipes by name', function () {
    Http::fake([RECIPE_API.'/recipes*' => Http::response(['data' => []])]);

    Native::test(RecipeIndex::class)
        ->set('search', 'chili')
        ->assertSee('No recipes match that search.');

    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && str_contains($request->url(), 'search=chili'));
});

it('imports a recipe from a link and opens it as a draft to check', function () {
    Http::fake([
        RECIPE_API.'/recipes/import' => Http::response(['data' => recipe(['id' => null, 'name' => 'Imported Soup'])]),
        RECIPE_API.'/recipes*' => Http::response(['data' => []]),
    ]);

    Native::test(RecipeIndex::class)
        ->set('importUrl', 'https://example.com/soup')
        ->call('importRecipe')
        ->assertNavigatedTo('/recipes/new')
        ->assertSet('importUrl', '');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/recipes/import') && $request['url'] === 'https://example.com/soup');
});

it('shows the import error when no recipe is found', function () {
    Http::fake([
        RECIPE_API.'/recipes/import' => Http::response(['message' => 'Couldn\'t find a recipe on that page.'], 422),
        RECIPE_API.'/recipes*' => Http::response(['data' => []]),
    ]);

    Native::test(RecipeIndex::class)
        ->set('importUrl', 'https://example.com/nothing')
        ->call('importRecipe')
        ->assertSee('Couldn\'t find a recipe on that page.');
});

it('sends a signed-out user to sign in on a 401', function () {
    Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);
    $this->session->shouldReceive('signOut')->once();

    Native::test(RecipeIndex::class)->assertReplacedWith('/sign-in');
});

it('shows a recipe with its ingredients and steps', function () {
    Http::fake([RECIPE_API.'/recipes/7' => Http::response(['data' => recipe()])]);

    Native::test(RecipeShow::class, params: ['id' => 7])
        ->assertSee('Lemon Chicken')
        ->assertSee('2 chicken breasts')
        ->assertSee('1. Season the chicken.')
        ->assertSee('Great with rice.');
});

it('deletes a recipe after confirming', function () {
    Http::fake([
        RECIPE_API.'/recipes/7' => Http::sequence()
            ->push(['data' => recipe()])
            ->push('', 204),
    ]);

    Native::test(RecipeShow::class, params: ['id' => 7])
        ->call('confirmDelete')
        ->assertNativeCalledTimes('Dialog.Alert', 1)
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Delete'])
        ->assertWentBack();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/recipes/7'));
});

it('creates a recipe from the form', function () {
    Http::fake([RECIPE_API.'/recipes' => Http::response(['data' => recipe()], 201)]);

    Native::test(RecipeForm::class)
        ->set('name', 'Lemon Chicken')
        ->set('ingredients', "2 chicken breasts\n\n1 lemon ")
        ->set('steps', 'Bake.')
        ->set('totalMinutes', '35')
        ->call('save')
        ->assertReplacedWith('/recipes/7');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['name'] === 'Lemon Chicken'
        && $request['ingredients'] === ['2 chicken breasts', '1 lemon']
        && $request['steps'] === ['Bake.']
        && $request['total_minutes'] === 35
        && $request['servings'] === null);
});

it('fills the form from an imported draft', function () {
    Native::test(RecipeForm::class, data: ['draft' => recipe(['id' => null])])
        ->assertSet('name', 'Lemon Chicken')
        ->assertSet('ingredients', "2 chicken breasts\n1 lemon")
        ->assertSet('totalMinutes', '35');
});

it('edits an existing recipe', function () {
    Http::fake([
        RECIPE_API.'/recipes/7' => Http::sequence()
            ->push(['data' => recipe()])
            ->push(['data' => recipe(['name' => 'Better Chicken'])]),
    ]);

    Native::test(RecipeForm::class, params: ['id' => 7])
        ->assertSet('name', 'Lemon Chicken')
        ->set('name', 'Better Chicken')
        ->call('save')
        ->assertWentBack();

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request['name'] === 'Better Chicken');
});

it('shows validation errors from the API', function () {
    Http::fake([RECIPE_API.'/recipes' => Http::response(['message' => 'Invalid.', 'errors' => ['name' => ['The name field is required.']]], 422)]);

    Native::test(RecipeForm::class)
        ->call('save')
        ->assertSee('The name field is required.');
});
