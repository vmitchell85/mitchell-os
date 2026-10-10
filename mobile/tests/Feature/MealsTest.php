<?php

use App\NativeComponents\Meals\MealIndex;
use App\NativeComponents\Meals\MealShow;
use App\Services\Api\Session;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;

/**
 * @return array<string, mixed>
 */
function mealPayload(int $id = 1, bool $draft = true, string $name = 'Chicken tacos'): array
{
    return [
        'id' => $id,
        'date' => '2026-10-12',
        'category' => 'Dinner',
        'name' => $name,
        'ingredients' => ['Chicken', 'Tortillas'],
        'note' => 'Taco night',
        'recipe_id' => null,
        'is_draft' => $draft,
        'approved_at' => $draft ? null : '2026-10-10T01:30:00+00:00',
        'added_by' => 1,
        'repeats_in_skylight' => false,
    ];
}

it('lists the week grouped by day with status badges', function () {
    Http::fake(['*/meals*' => Http::response(['data' => [
        mealPayload(1, true, 'Chicken tacos'),
        [...mealPayload(2, false, 'Pasta night'), 'date' => '2026-10-13'],
    ]])]);

    Native::test(MealIndex::class)
        ->assertSee('Monday, October 12')
        ->assertSee('Tuesday, October 13')
        ->assertSee('Chicken tacos')
        ->assertSee('Draft')
        ->assertSee('Approved')
        ->assertSee('1 suggestion is waiting for approval.');

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && str_contains($request->url(), '/api/v1/meals')
        && isset($request['from'], $request['to']));
});

it('shows an empty state when nothing is planned', function () {
    Http::fake(['*/meals*' => Http::response(['data' => []])]);

    Native::test(MealIndex::class)->assertSee('No meals planned for this week yet.');
});

it('reloads the week on refresh and when changing weeks', function () {
    Http::fake(['*/meals*' => Http::response(['data' => []])]);

    Native::test(MealIndex::class)
        ->call('nextWeek')
        ->assertSet('weekOffset', 1)
        ->call('previousWeek')
        ->call('refresh')
        ->assertSet('weekOffset', 0);

    Http::assertSentCount(4);
});

it('plans the week in two steps so progress can show first', function () {
    Http::fake([
        '*/meal-plans' => Http::response(['data' => [mealPayload(1), mealPayload(2, true, 'Soup')]], 200),
        '*/meals*' => Http::response(['data' => [mealPayload(1), mealPayload(2, true, 'Soup')]]),
    ]);

    Native::test(MealIndex::class)
        ->call('planWeek')
        ->assertSet('planning', true)
        ->assertSee('Claude is drafting meals for the week.')
        ->call('continuePlanning')
        ->assertSet('planning', false)
        ->assertSee('Claude suggested 2 meals.')
        ->assertSee('Soup');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/v1/meal-plans')
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request['week']) === 1);
});

it('does nothing on a stray poll when no plan was requested', function () {
    Http::fake(['*/meals*' => Http::response(['data' => []])]);

    Native::test(MealIndex::class)->call('continuePlanning')->assertSet('planning', false);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

it('reports a slow plan without failing', function () {
    Http::fake([
        '*/meal-plans' => fn () => throw new ConnectionException('timed out'),
        '*/meals*' => Http::response(['data' => []]),
    ]);

    Native::test(MealIndex::class)
        ->call('planWeek')
        ->call('continuePlanning')
        ->assertSet('planning', false)
        ->assertSee('Claude is still working on it.');
});

it('shows the server message when planning fails', function () {
    Http::fake([
        '*/meal-plans' => Http::response(['message' => 'Claude is busy.'], 500),
        '*/meals*' => Http::response(['data' => []]),
    ]);

    Native::test(MealIndex::class)->call('planWeek')->call('continuePlanning')->assertSee('Claude is busy.');
});

it('signs out and returns to sign-in on a 401', function () {
    Http::fake(['*/meals*' => Http::response(['message' => 'Unauthenticated.'], 401)]);
    $this->mock(Session::class, function ($mock) {
        $mock->shouldReceive('token')->andReturn('stale');
        $mock->shouldReceive('serverUrl')->andReturn('http://example.test');
        $mock->shouldReceive('signOut')->once();
    });

    Native::test(MealIndex::class)->assertReplacedWith('/sign-in');
});

it('shows a meal with its details', function () {
    Http::fake(['*/meals/1' => Http::response(['data' => mealPayload(1, false)])]);

    Native::test(MealShow::class, params: ['id' => 1])
        ->assertSee('Chicken tacos')
        ->assertSee('Dinner · Monday, October 12')
        ->assertSee('Chicken')
        ->assertSee('Taco night')
        ->assertSee('Approved October 9 at 9:30 PM')
        ->assertDontSee('Suggest something different');
});

it('approves a draft', function () {
    Http::fake([
        '*/meals/1/approve' => Http::response(['data' => mealPayload(1, false)]),
        '*/meals/1' => Http::response(['data' => mealPayload(1, true)]),
    ]);

    Native::test(MealShow::class, params: ['id' => 1])
        ->assertSee('Suggest something different')
        ->call('approve')
        ->assertSee('Approved. It will reach Skylight at the next sync.')
        ->assertDontSee('Suggest something different');
});

it('regenerates a draft with optional notes in two steps', function () {
    Http::fake([
        '*/meals/1/regenerate' => Http::response(['data' => mealPayload(1, true, 'Lentil soup')]),
        '*/meals/1' => Http::response(['data' => mealPayload(1, true)]),
    ]);

    Native::test(MealShow::class, params: ['id' => 1])
        ->set('regenerateNotes', 'something lighter')
        ->call('regenerate')
        ->assertSet('busy', true)
        ->assertSee('Claude is thinking…')
        ->call('continueRegenerating')
        ->assertSet('busy', false)
        ->assertSee('Lentil soup')
        ->assertSet('regenerateNotes', '');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/v1/meals/1/regenerate')
        && $request['notes'] === 'something lighter');
});

it('does not offer to regenerate an approved meal', function () {
    Http::fake(['*/meals/1' => Http::response(['data' => mealPayload(1, false)])]);

    Native::test(MealShow::class, params: ['id' => 1])->call('regenerate')->assertSet('busy', false);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

it('edits a meal', function () {
    Http::fake([
        '*/meals/1' => Http::sequence()
            ->push(['data' => mealPayload(1, true)])
            ->push(['data' => mealPayload(1, true, 'Beef tacos')]),
    ]);

    Native::test(MealShow::class, params: ['id' => 1])
        ->call('startEditing')
        ->assertSet('editing', true)
        ->set('name', 'Beef tacos')
        ->set('ingredients', "Beef\nTortillas\n")
        ->call('save')
        ->assertSet('editing', false)
        ->assertSee('Beef tacos')
        ->assertSee('Saved.');

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request['name'] === 'Beef tacos'
        && $request['ingredients'] === ['Beef', 'Tortillas']
        && $request['category'] === 'Dinner'
        && $request['date'] === '2026-10-12');
});

it('shows validation errors when saving fails', function () {
    Http::fake([
        '*/meals/1' => Http::sequence()
            ->push(['data' => mealPayload(1, true)])
            ->push(['message' => 'The name field is required.'], 422),
    ]);

    Native::test(MealShow::class, params: ['id' => 1])
        ->call('startEditing')
        ->set('name', '')
        ->call('save')
        ->assertSet('editing', true)
        ->assertSee('The name field is required.');
});

it('removes a meal after confirming and goes back', function () {
    Http::fake([
        '*/meals/1' => Http::sequence()
            ->push(['data' => mealPayload(1, true)])
            ->push('', 204),
    ]);

    Native::test(MealShow::class, params: ['id' => 1])
        ->call('confirmDelete')
        ->assertNativeCalled('Dialog.Alert')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Remove'])
        ->assertWentBack();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/api/v1/meals/1'));
});

it('shows a message when the meal is gone', function () {
    Http::fake(['*/meals/9' => Http::response(['message' => 'Not found.'], 404)]);

    Native::test(MealShow::class, params: ['id' => 9])->assertSee('This meal is no longer on the plan.');
});
