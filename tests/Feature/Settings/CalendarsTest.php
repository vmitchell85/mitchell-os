<?php

use App\Models\SharedCalendar;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    fakeMacHelper(calendars: [
        ['id' => 'WORK-1', 'title' => 'Work', 'account' => 'vince@example.com', 'color' => '#4285F4'],
        ['id' => 'HOME-1', 'title' => 'Home', 'account' => 'iCloud', 'color' => null],
    ]);
});

it('lists the calendars on this mac by account', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('calendars.edit'))
        ->assertOk()
        ->assertSeeInOrder(['vince@example.com', 'Work', 'iCloud', 'Home']);
});

it('shares a calendar with the household and labels whose it is', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::settings.calendars')
        ->call('toggle', 0)
        ->set('labels.0', 'Vince – Work');

    expect(SharedCalendar::sole())
        ->calendar_id->toBe('WORK-1')
        ->title->toBe('Work')
        ->label->toBe('Vince – Work');
});

it('stops sharing a calendar', function () {
    SharedCalendar::factory()->create(['calendar_id' => 'HOME-1']);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::settings.calendars')
        ->call('toggle', 1);

    expect(SharedCalendar::count())->toBe(0);
});

it('keeps a shared calendar this mac no longer has, so it can be unshared', function () {
    SharedCalendar::factory()->create(['calendar_id' => 'GONE-1', 'title' => 'Old job']);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::settings.calendars')
        ->assertSee('No longer on this Mac')
        ->assertSee('Old job')
        ->call('toggle', 2);

    expect(SharedCalendar::count())->toBe(0);
});

it('explains why the calendars cannot be read', function () {
    fakeMacHelper(error: 'Calendar access is turned off.');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::settings.calendars')
        ->assertSee('Calendar access is turned off.');
});
