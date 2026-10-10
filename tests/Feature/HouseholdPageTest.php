<?php

use App\Models\HouseholdNote;
use App\Models\HouseholdSetting;
use App\Models\User;
use Livewire\Livewire;

it('redirects guests to the login page', function () {
    $this->get(route('household'))->assertRedirect(route('login'));
});

it('shows the notes with who added them', function () {
    $user = User::factory()->create(['name' => 'Marianne']);
    HouseholdNote::factory()->create(['body' => 'We have soccer practice on Tuesdays.', 'added_by' => $user->id]);

    $this->actingAs($user)->get(route('household'))
        ->assertOk()
        ->assertSee('We have soccer practice on Tuesdays.')
        ->assertSee('Added by Marianne');
});

it('adds, edits and removes a note', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test('pages::household')
        ->set('note', ' The kids go to bed at 8 PM. ')
        ->call('addNote')
        ->assertSet('note', '');

    $note = HouseholdNote::sole();
    expect($note)->body->toBe('The kids go to bed at 8 PM.')->added_by->toBe($user->id);

    $component->call('startEditing', $note->id)
        ->set('editNote', 'The kids go to bed at 8:30 PM.')
        ->call('saveNote')
        ->assertSet('editingNoteId', null);

    expect($note->fresh()->body)->toBe('The kids go to bed at 8:30 PM.');

    $component->call('removeNote', $note->id);

    expect(HouseholdNote::count())->toBe(0);
});

it('requires a note', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::household')
        ->call('addNote')
        ->assertHasErrors(['note' => 'required']);
});

it('saves the food rules', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::household')
        ->set('foodRules', ' No seafood. ')
        ->call('saveFoodRules');

    expect(HouseholdSetting::get(HouseholdSetting::FoodRules))->toBe('No seafood.');
});

it('saves the weather zip code and home address', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::household')
        ->assertSet('weatherZip', '43081')
        ->set('weatherZip', '43215')
        ->set('homeAddress', ' 1 Main St, Columbus, OH ')
        ->call('saveHome')
        ->assertHasNoErrors();

    expect(HouseholdSetting::weatherZip())->toBe('43215')
        ->and(HouseholdSetting::homeAddress())->toBe('1 Main St, Columbus, OH');
});

it('requires a five digit zip code', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::household')
        ->set('weatherZip', '4308')
        ->call('saveHome')
        ->assertHasErrors(['weatherZip' => 'regex']);
});
