<?php

use App\NativeComponents\Lists\ShoppingListIndex;
use App\NativeComponents\Lists\ShoppingListShow;
use App\Services\Api\Session;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    $this->mock(Session::class, function ($mock) {
        $mock->shouldReceive('token')->andReturn('test-token');
        $mock->shouldReceive('serverUrl')->andReturn('http://mitchell.test');
        $mock->shouldReceive('isSignedIn')->andReturn(true);
        $mock->shouldReceive('signOut')->byDefault();
    });
});

function listPayload(array $items = []): array
{
    return ['data' => ['id' => 7, 'name' => 'Costco', 'items' => $items]];
}

function itemPayload(int $id, string $name, bool $completed = false, ?string $section = null): array
{
    return ['id' => $id, 'name' => $name, 'section' => $section, 'completed' => $completed];
}

it('lists the household lists with how many items are left', function () {
    Http::fake(['*/shopping-lists' => Http::response(['data' => [
        ['id' => 1, 'name' => 'Groceries', 'pending_items_count' => 3],
        ['id' => 2, 'name' => 'Costco', 'pending_items_count' => 1],
    ]])]);

    Native::visit('/lists')
        ->assertSee('Groceries')
        ->assertSee('3 items to get')
        ->assertSee('1 item to get');
});

it('opens a list from the index', function () {
    Http::fake(['*/shopping-lists' => Http::response(['data' => [['id' => 1, 'name' => 'Groceries', 'pending_items_count' => 0]]])]);

    Native::test(ShoppingListIndex::class)
        ->call('openList', 1)
        ->assertNavigatedTo('/lists/1');
});

it('creates a list and opens it', function () {
    Http::fake([
        '*/shopping-lists' => Http::sequence()
            ->push(['data' => []])
            ->push(['data' => ['id' => 9, 'name' => 'Hardware']], 201),
    ]);

    Native::test(ShoppingListIndex::class)
        ->set('newName', 'Hardware')
        ->call('createList')
        ->assertNavigatedTo('/lists/9');

    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['name'] === 'Hardware');
});

it('does not create a list without a name', function () {
    Http::fake(['*/shopping-lists' => Http::response(['data' => []])]);

    Native::test(ShoppingListIndex::class)
        ->set('newName', '  ')
        ->call('createList')
        ->assertSet('error', 'Give the list a name.');

    Http::assertSentCount(1);
});

it('sends the user to sign in when the token is rejected', function () {
    Http::fake(['*' => Http::response([], 401)]);

    Native::test(ShoppingListIndex::class)->assertReplacedWith('/sign-in');
});

it('shows a list with its items', function () {
    Http::fake(['*/shopping-lists/7' => Http::response(listPayload([
        itemPayload(1, 'Milk', false, 'Dairy'),
        itemPayload(2, 'Eggs', true),
    ]))]);

    Native::visit('/lists/7')
        ->assertScreen(ShoppingListShow::class)
        ->assertSee('Milk')
        ->assertSee('Eggs')
        ->assertNavTitle('Costco');
});

it('ticks an item off optimistically', function () {
    Http::fake([
        '*/shopping-lists/7' => Http::response(listPayload([itemPayload(1, 'Milk')])),
        '*/shopping-lists/7/items/1' => Http::response(['data' => itemPayload(1, 'Milk', true)]),
    ]);

    Native::test(ShoppingListShow::class, params: ['id' => 7])
        ->call('toggleItem', 1, true)
        ->assertSet('items.0.completed', true);

    Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request->url() === 'http://mitchell.test/api/v1/shopping-lists/7/items/1' && $request['completed'] === true);
});

it('flips an item when the toggle carries no value', function () {
    Http::fake([
        '*/shopping-lists/7' => Http::response(listPayload([itemPayload(1, 'Milk', true)])),
        '*/shopping-lists/7/items/1' => Http::response(['data' => itemPayload(1, 'Milk')]),
    ]);

    Native::test(ShoppingListShow::class, params: ['id' => 7])
        ->call('toggleItem', 1)
        ->assertSet('items.0.completed', false);
});

it('puts a tick back when the server refuses it', function () {
    Http::fake([
        '*/shopping-lists/7' => Http::response(listPayload([itemPayload(1, 'Milk')])),
        '*/shopping-lists/7/items/1' => Http::response([], 500),
    ]);

    Native::test(ShoppingListShow::class, params: ['id' => 7])
        ->call('toggleItem', 1, true)
        ->assertSet('items.0.completed', false)
        ->assertSet('error', 'That item could not be updated.');
});

it('adds an item', function () {
    Http::fake([
        '*/shopping-lists/7' => Http::response(listPayload()),
        '*/shopping-lists/7/items' => Http::response(['data' => itemPayload(5, 'Bread')], 201),
    ]);

    Native::test(ShoppingListShow::class, params: ['id' => 7])
        ->set('newItem', 'Bread')
        ->call('addItem')
        ->assertSet('newItem', '')
        ->assertSet('items.0.name', 'Bread');
});

it('ignores a blank item', function () {
    Http::fake(['*/shopping-lists/7' => Http::response(listPayload())]);

    Native::test(ShoppingListShow::class, params: ['id' => 7])
        ->set('newItem', ' ')
        ->call('addItem');

    Http::assertSentCount(1);
});

it('deletes an item', function () {
    Http::fake([
        '*/shopping-lists/7' => Http::response(listPayload([itemPayload(1, 'Milk'), itemPayload(2, 'Eggs')])),
        '*/shopping-lists/7/items/1' => Http::response('', 204),
    ]);

    Native::test(ShoppingListShow::class, params: ['id' => 7])
        ->call('deleteItem', 1)
        ->assertSet('items.0.name', 'Eggs');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE');
});

it('restores a deleted item when the server refuses', function () {
    Http::fake([
        '*/shopping-lists/7' => Http::response(listPayload([itemPayload(1, 'Milk'), itemPayload(2, 'Eggs')])),
        '*/shopping-lists/7/items/2' => Http::response([], 500),
    ]);

    Native::test(ShoppingListShow::class, params: ['id' => 7])
        ->call('deleteItem', 2)
        ->assertSet('items.1.name', 'Eggs')
        ->assertSet('error', 'That item could not be removed.');
});

it('proposes items from meals and adds only the ones kept', function () {
    Http::fake([
        '*/shopping-lists/7' => Http::response(listPayload()),
        '*/shopping-lists/7/proposals' => Http::response(['data' => [
            ['name' => '1 lb ground beef', 'section' => 'Meat', 'meals' => ['Chili']],
            ['name' => '2 onions', 'section' => 'Produce', 'meals' => ['Chili']],
        ]]),
        '*/shopping-lists/7/items' => Http::response(['data' => itemPayload(3, '1 lb ground beef', false, 'Meat')], 201),
    ]);

    Native::test(ShoppingListShow::class, params: ['id' => 7])
        ->call('openBuilder')
        ->set('from', '2026-10-12')
        ->set('to', '2026-10-18')
        ->call('buildFromMeals')
        ->assertSet('hasProposals', true)
        ->assertSet('selectedProposals', [0, 1])
        ->call('toggleProposal', 1, false)
        ->call('addSelectedProposals')
        ->assertSet('showBuilder', false)
        ->assertSet('items.0.name', '1 lb ground beef');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/proposals') && $request['from'] === '2026-10-12' && $request['to'] === '2026-10-18');
    Http::assertSentCount(3);
});

it('shows a message when building from meals fails', function () {
    Http::fake([
        '*/shopping-lists/7' => Http::response(listPayload()),
        '*/shopping-lists/7/proposals' => Http::response(['message' => 'Claude is busy'], 502),
    ]);

    Native::test(ShoppingListShow::class, params: ['id' => 7])
        ->call('openBuilder')
        ->call('buildFromMeals')
        ->assertSet('hasProposals', false)
        ->assertSet('error', 'The meals could not be turned into a list. Try again.');
});
