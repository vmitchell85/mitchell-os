<?php

use App\Actions\Recipes\ImportRecipe;
use App\Actions\Recipes\RecipeImportException;
use App\Models\ClaudeRun;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * A public address, so the import doesn't need to look up a host name.
 */
const RecipePage = 'https://93.184.215.14/lasagna';

function recipeFromClaude(array $overrides = []): string
{
    return claudeStructuredResult([
        'name' => 'Classic lasagna',
        'ingredients' => ['1 lb ground beef', ' 12 lasagna noodles ', ''],
        'steps' => ['Brown the beef.', 'Layer and bake for 45 minutes.'],
        'servings' => '8',
        'total_minutes' => 90,
        'notes' => '',
        ...$overrides,
    ]);
}

it('gives claude the page\'s recipe data and returns the recipe to check', function () {
    Http::fake([RecipePage => Http::response('<html><head><script type="application/ld+json">{"@type":"Recipe","name":"Classic lasagna","recipeIngredient":["1 lb ground beef"]}</script></head><body>Life story…</body></html>')]);
    $captured = fakeClaudeProcess(recipeFromClaude());
    $user = User::factory()->create();

    $recipe = app(ImportRecipe::class)->handle($user, RecipePage);

    expect($recipe)->toBe([
        'name' => 'Classic lasagna',
        'ingredients' => ['1 lb ground beef', '12 lasagna noodles'],
        'steps' => ['Brown the beef.', 'Layer and bake for 45 minutes.'],
        'servings' => '8',
        'total_minutes' => 90,
        'notes' => null,
        'source_url' => RecipePage,
    ]);
    expect($captured->process->input)
        ->toContain('Recipe data from the page:')
        ->toContain('"recipeIngredient":["1 lb ground beef"]')
        ->not->toContain('Life story');
    expect(claudeOption($captured->process->command, '--tools'))->toBe('');
    expect(ClaudeRun::sole())->feature->toBe('recipe-import')->user_id->toBe($user->id);
});

it('falls back to the page text when there is no recipe data', function () {
    Http::fake([RecipePage => Http::response('<html><style>p{}</style><body><h1>Lasagna</h1><p>1 lb ground beef</p><script>track()</script></body></html>')]);
    $captured = fakeClaudeProcess(recipeFromClaude());

    app(ImportRecipe::class)->handle(User::factory()->create(), RecipePage);

    expect($captured->process->input)
        ->toContain('Page text:')
        ->toContain('Lasagna 1 lb ground beef')
        ->not->toContain('track()');
});

it('says so when the page has no recipe', function () {
    Http::fake([RecipePage => Http::response('<html><body>About us</body></html>')]);
    fakeClaudeProcess(recipeFromClaude(['name' => '']));

    app(ImportRecipe::class)->handle(User::factory()->create(), RecipePage);
})->throws(RecipeImportException::class, 'Couldn\'t find a recipe on that page.');

it('refuses addresses on this machine or the home network, even after a redirect', function (string $url) {
    Http::fake([RecipePage => Http::response('', 302, ['Location' => 'http://192.168.1.10/admin'])]);
    Process::fake();

    expect(fn () => app(ImportRecipe::class)->handle(User::factory()->create(), $url))
        ->toThrow(RecipeImportException::class, 'That page isn\'t on the public internet.');

    Process::assertNothingRan();
})->with([
    'localhost' => 'http://127.0.0.1:8000/user',
    'home network' => 'http://192.168.1.10/',
    'redirect to the home network' => RecipePage,
]);

it('reports a page that returns an error', function () {
    Http::fake([RecipePage => Http::response('Not found', 404)]);

    app(ImportRecipe::class)->handle(User::factory()->create(), RecipePage);
})->throws(RecipeImportException::class, 'That page returned an error (404).');
