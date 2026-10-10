<?php

use App\Models\Meal;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('redirects guests to the login page', function () {
    $this->get(route('recipes'))->assertRedirect(route('login'));
});

it('lists recipes by name and searches them', function () {
    Recipe::factory()->create(['name' => 'Mac and cheese']);
    Recipe::factory()->create(['name' => 'Chicken tacos']);

    $this->actingAs(User::factory()->create())->get(route('recipes'))
        ->assertOk()
        ->assertSeeInOrder(['Chicken tacos', 'Mac and cheese']);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::recipes.index')
        ->set('search', 'taco')
        ->assertSee('Chicken tacos')
        ->assertDontSee('Mac and cheese');
});

it('adds a recipe, one ingredient and step per line', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::recipes.form')
        ->set('name', 'Beef chili')
        ->set('servings', '6')
        ->set('totalMinutes', '45')
        ->set('ingredients', "1 lb ground beef\r\n\r\n 1 can kidney beans ")
        ->set('steps', "Brown the beef.\nSimmer for 30 minutes.")
        ->call('save')
        ->assertHasNoErrors();

    $recipe = Recipe::sole();
    expect($recipe)
        ->name->toBe('Beef chili')
        ->total_minutes->toBe(45)
        ->ingredientList()->toBe(['1 lb ground beef', '1 can kidney beans'])
        ->stepList()->toBe(['Brown the beef.', 'Simmer for 30 minutes.'])
        ->added_by->toBe($user->id);
});

it('edits a recipe', function () {
    $recipe = Recipe::factory()->create(['name' => 'Chili']);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::recipes.form', ['recipe' => $recipe])
        ->assertSet('name', 'Chili')
        ->set('name', 'Beef chili')
        ->call('save')
        ->assertRedirect(route('recipes.show', $recipe));

    expect($recipe->fresh()->name)->toBe('Beef chili');
});

it('fills the form from a recipe link for checking before saving', function () {
    Http::fake(['https://93.184.215.14/*' => Http::response('<script type="application/ld+json">{"@type":"Recipe"}</script>')]);
    fakeClaudeProcess(claudeStructuredResult([
        'name' => 'Classic lasagna', 'ingredients' => ['1 lb ground beef'], 'steps' => ['Bake.'], 'servings' => '8', 'total_minutes' => 90, 'notes' => '',
    ]));

    Livewire::actingAs(User::factory()->create())
        ->test('pages::recipes.form')
        ->set('importUrl', 'https://93.184.215.14/lasagna')
        ->call('import')
        ->assertHasNoErrors()
        ->assertSet('name', 'Classic lasagna')
        ->assertSet('ingredients', '1 lb ground beef')
        ->assertSet('totalMinutes', '90')
        ->assertSet('sourceUrl', 'https://93.184.215.14/lasagna');

    expect(Recipe::count())->toBe(0);
});

it('shows why an import failed', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::recipes.form')
        ->set('importUrl', 'http://192.168.1.10/recipe')
        ->call('import')
        ->assertHasErrors(['importUrl']);
});

it('shows a recipe and adds it to the meal plan with its ingredients', function () {
    $user = User::factory()->create();
    $recipe = Recipe::factory()->create(['name' => 'Beef chili', 'ingredients' => "1 lb ground beef\n1 can kidney beans", 'steps' => 'Simmer.']);

    $this->actingAs($user)->get(route('recipes.show', $recipe))
        ->assertOk()
        ->assertSeeInOrder(['Beef chili', '1 can kidney beans', 'Simmer.']);

    Livewire::actingAs($user)
        ->test('pages::recipes.show', ['recipe' => $recipe])
        ->set('planDate', '2026-10-14')
        ->set('planCategory', 'Dinner')
        ->call('plan')
        ->assertRedirect(route('meals', ['week' => '2026-10-12']));

    expect(Meal::sole())
        ->name->toBe('Beef chili')
        ->description->toBe("1 lb ground beef\n1 can kidney beans")
        ->recipe_id->toBe($recipe->id)
        ->isDraft()->toBeFalse();
});

it('deletes a recipe but keeps meals planned from it', function () {
    $recipe = Recipe::factory()->create();
    $meal = Meal::factory()->create(['recipe_id' => $recipe->id]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::recipes.show', ['recipe' => $recipe])
        ->call('delete')
        ->assertRedirect(route('recipes'));

    expect(Recipe::count())->toBe(0);
    expect($meal->fresh())->not->toBeNull()->recipe_id->toBeNull();
});

it('saves a meal as a recipe and links them', function () {
    $user = User::factory()->create();
    $meal = Meal::factory()->create(['name' => 'Sloppy joes', 'description' => "1 lb ground beef\nBuns", 'note' => 'Kids love it']);

    Livewire::actingAs($user)
        ->test('pages::meals')
        ->call('saveAsRecipe', $meal->id)
        ->assertRedirect(route('recipes.edit', Recipe::sole()));

    expect(Recipe::sole())
        ->name->toBe('Sloppy joes')
        ->ingredients->toBe("1 lb ground beef\nBuns")
        ->notes->toBe('Kids love it')
        ->added_by->toBe($user->id);
    expect($meal->fresh()->recipe_id)->toBe(Recipe::sole()->id);
});
