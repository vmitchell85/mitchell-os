<?php

use App\Models\HouseholdNote;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lists, adds, edits and removes notes', function () {
    $user = Sanctum::actingAs(User::factory()->create());
    HouseholdNote::factory()->create(['body' => 'We like leftovers for lunch.']);

    $this->getJson(route('api.v1.notes.index'))->assertOk()->assertJsonPath('data.0.body', 'We like leftovers for lunch.');

    $id = $this->postJson(route('api.v1.notes.store'), ['body' => 'Soccer on Tuesdays.'])
        ->assertCreated()
        ->assertJsonPath('data.added_by', $user->id)
        ->json('data.id');

    $this->patchJson(route('api.v1.notes.update', $id), ['body' => 'Soccer on Tuesdays and Thursdays.'])->assertOk();
    expect(HouseholdNote::find($id)->body)->toBe('Soccer on Tuesdays and Thursdays.');

    $this->deleteJson(route('api.v1.notes.destroy', $id))->assertNoContent();
    expect(HouseholdNote::count())->toBe(1);
});

it('requires a note body', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson(route('api.v1.notes.store'), ['body' => ''])->assertJsonValidationErrors('body');
});
