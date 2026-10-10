<?php

use App\NativeComponents\Household\HouseholdIndex;
use App\Services\Api\Session;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

function fakeHousehold(array $notes = [['id' => 1, 'body' => 'Dad is vegetarian'], ['id' => 2, 'body' => 'Nut allergy']]): void
{
    Http::fake([
        '*/food-rules' => Http::response(['data' => ['food_rules' => 'No seafood']]),
        '*/notes' => Http::response(['data' => $notes]),
    ]);
}

it('shows the food rules and notes', function () {
    fakeHousehold();

    Native::visit('/household')
        ->assertSet('foodRules', 'No seafood')
        ->assertSee('Dad is vegetarian')
        ->assertSee('Nut allergy');
});

it('shows an empty state when there are no notes', function () {
    fakeHousehold([]);

    Native::visit('/household')->assertSee('No notes yet.');
});

it('saves edited food rules', function () {
    fakeHousehold();

    Native::test(HouseholdIndex::class)
        ->set('foodRules', 'No seafood or peanuts')
        ->call('saveFoodRules')
        ->assertSet('status', 'Food rules saved.');

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/api/v1/food-rules')
        && $request['food_rules'] === 'No seafood or peanuts');
});

it('adds a note', function () {
    Http::fake([
        '*/food-rules' => Http::response(['data' => ['food_rules' => null]]),
        '*/notes' => Http::sequence()
            ->push(['data' => []])
            ->push(['data' => ['id' => 5, 'body' => 'Likes spicy food']]),
    ]);

    Native::test(HouseholdIndex::class)
        ->set('newNote', 'Likes spicy food')
        ->call('addNote')
        ->assertSet('newNote', '')
        ->assertSee('Likes spicy food');
});

it('does not post an empty note', function () {
    fakeHousehold();

    Native::test(HouseholdIndex::class)->set('newNote', '   ')->call('addNote');

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

it('edits a note', function () {
    Http::fake([
        '*/food-rules' => Http::response(['data' => ['food_rules' => null]]),
        '*/notes/1' => Http::response(['data' => ['id' => 1, 'body' => 'Dad eats fish']]),
        '*/notes' => Http::response(['data' => [['id' => 1, 'body' => 'Dad is vegetarian']]]),
    ]);

    Native::test(HouseholdIndex::class)
        ->call('editNote', 1)
        ->assertSet('editingBody', 'Dad is vegetarian')
        ->set('editingBody', 'Dad eats fish')
        ->call('saveNote')
        ->assertSet('editingNoteId', null)
        ->assertSee('Dad eats fish');
});

it('deletes a note', function () {
    Http::fake([
        '*/food-rules' => Http::response(['data' => ['food_rules' => null]]),
        '*/notes/1' => Http::response(null, 204),
        '*/notes' => Http::response(['data' => [['id' => 1, 'body' => 'Dad is vegetarian']]]),
    ]);

    Native::test(HouseholdIndex::class)
        ->call('deleteNote', 1)
        ->assertDontSee('Dad is vegetarian')
        ->assertSee('No notes yet.');
});

it('shows a validation error from the server', function () {
    Http::fake([
        '*/food-rules' => Http::response(['data' => ['food_rules' => null]]),
        '*/notes' => Http::sequence()
            ->push(['data' => []])
            ->push(['message' => 'Invalid', 'errors' => ['body' => ['The body field must not be greater than 500 characters.']]], 422),
    ]);

    Native::test(HouseholdIndex::class)
        ->set('newNote', 'too long')
        ->call('addNote')
        ->assertSet('error', 'The body field must not be greater than 500 characters.');
});

it('sends the user to sign in when the token is rejected', function () {
    Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);
    $this->mock(Session::class, function ($mock) {
        $mock->shouldReceive('signOut')->atLeast()->once();
        $mock->shouldReceive('token')->andReturn('bad');
        $mock->shouldReceive('serverUrl')->andReturn('http://localhost');
    });

    Native::test(HouseholdIndex::class)->assertReplacedWith('/sign-in');
});
