<?php

use App\Actions\Skylight\SyncMeals;
use App\Actions\Skylight\SyncReport;
use App\Models\Meal;
use App\Services\Skylight\SkylightClient;
use Tests\Fakes\FakeSkylight;

beforeEach(function () {
    $this->travelTo('2026-10-09 12:00:00');
});

function syncMeals(FakeSkylight $skylight): SyncReport
{
    return (new SyncMeals(new SkylightClient($skylight)))->handle();
}

/**
 * Skylight with a one-off bolognese on the 12th and sloppy joes every Friday, already synced once.
 */
function syncedMeals(): FakeSkylight
{
    $skylight = new FakeSkylight;
    $skylight->addMeal('Spaghetti bolognese', ['2026-10-12'], description: 'Ground beef, tomatoes', id: '3001');
    $skylight->addMeal('Sloppy joes', ['2026-10-09', '2026-10-16', '2026-10-23'], repeats: true, id: '3002');
    syncMeals($skylight);
    $skylight->writes = [];

    return $skylight;
}

function localMeal(string $skylightId, string $date): Meal
{
    return Meal::withTrashed()->where('skylight_id', $skylightId)->whereDate('skylight_date', $date)->sole();
}

it('imports each date of skylight meals in the sync window as an approved meal', function () {
    $skylight = new FakeSkylight;
    $skylight->addMeal('Spaghetti bolognese', ['2026-10-12'], description: 'Ground beef, tomatoes', note: 'Double batch');
    $skylight->addMeal('Sloppy joes', ['2026-10-09', '2026-10-16', '2026-12-25'], repeats: true);
    $skylight->addMeal('Porridge', ['2026-09-01'], category: 'Breakfast');

    $report = syncMeals($skylight);

    expect(Meal::orderBy('date')->get()->map(fn (Meal $meal) => [$meal->date->toDateString(), $meal->name, $meal->repeats_in_skylight, $meal->isDraft()])->all())
        ->toBe([
            ['2026-10-09', 'Sloppy joes', true, false],
            ['2026-10-12', 'Spaghetti bolognese', false, false],
            ['2026-10-16', 'Sloppy joes', true, false],
        ]);
    expect(Meal::where('name', 'Spaghetti bolognese')->sole())->description->toBe('Ground beef, tomatoes')->note->toBe('Double batch')->category->toBe('Dinner');
    expect($report->pulled)->toBe(3);
    expect($skylight->writes)->toBe([]);
});

it('makes no changes when nothing changed since the last sync', function () {
    $skylight = syncedMeals();

    $report = syncMeals($skylight);

    expect($report->hasChanges())->toBeFalse();
    expect($skylight->writes)->toBe([]);
});

it('plans approved meals made here in skylight, under their category, but never drafts', function () {
    $skylight = syncedMeals();
    $pancakes = Meal::factory()->create(['date' => '2026-10-11', 'category' => 'Breakfast', 'name' => 'Pancakes', 'note' => 'Lemon and sugar']);
    Meal::factory()->draft()->create(['date' => '2026-10-13', 'name' => 'Draft lasagna']);

    syncMeals($skylight);

    expect($skylight->meals[$pancakes->fresh()->skylight_id])->toMatchArray(['summary' => 'Pancakes', 'note' => 'Lemon and sugar', 'instances' => ['2026-10-11']]);
    expect($skylight->meals[$pancakes->fresh()->skylight_id]['meal_category']['label'])->toBe('Breakfast');
    expect(collect($skylight->meals)->pluck('summary'))->not->toContain('Draft lasagna');
});

it('pulls meals renamed and moved in skylight', function () {
    $skylight = syncedMeals();
    $skylight->meals['3001'] = [...$skylight->meals['3001'], 'summary' => 'Lasagna', 'instances' => ['2026-10-13']];

    syncMeals($skylight);

    expect(Meal::where('skylight_id', '3001')->sole())->name->toBe('Lasagna')->date->toDateString()->toBe('2026-10-13');
    expect($skylight->writes)->toBe([]);
});

it('sends changes made here, winning when both sides changed the same field', function () {
    $skylight = syncedMeals();
    localMeal('3001', '2026-10-12')->update(['name' => 'Bolognese bake', 'date' => '2026-10-14']);
    $skylight->meals['3001'] = [...$skylight->meals['3001'], 'summary' => 'Spag bol', 'note' => 'Use the big pan'];

    syncMeals($skylight);

    expect($skylight->meals['3001'])->toMatchArray(['summary' => 'Bolognese bake', 'note' => 'Use the big pan', 'instances' => ['2026-10-14']]);
    expect(Meal::where('skylight_id', '3001')->sole())
        ->name->toBe('Bolognese bake')->note->toBe('Use the big pan')->date->toDateString()->toBe('2026-10-14');
});

it('splits one changed date off a repeating meal, leaving the other dates alone', function () {
    $skylight = syncedMeals();
    localMeal('3002', '2026-10-16')->update(['name' => 'Fish pie']);

    syncMeals($skylight);

    expect($skylight->meals['3002']['instances'])->toBe(['2026-10-09', '2026-10-23']);
    $fishPie = Meal::where('name', 'Fish pie')->sole();
    expect($skylight->meals[$fishPie->skylight_id])->toMatchArray(['summary' => 'Fish pie', 'instances' => ['2026-10-16'], 'recurring' => false]);
    expect($fishPie->repeats_in_skylight)->toBeFalse();
    expect(syncMeals($skylight)->hasChanges())->toBeFalse();
});

it('removes meals here that were deleted in skylight', function () {
    $skylight = syncedMeals();
    unset($skylight->meals['3001']);

    $report = syncMeals($skylight);

    expect(Meal::withTrashed()->where('skylight_id', '3001')->exists())->toBeFalse();
    expect($report->removedHere)->toBe(1);
});

it('removes only that date from skylight when one date of a repeating meal is deleted here', function () {
    $skylight = syncedMeals();
    localMeal('3002', '2026-10-16')->delete();

    $report = syncMeals($skylight);

    expect($skylight->meals['3002']['instances'])->toBe(['2026-10-09', '2026-10-23']);
    expect(Meal::withTrashed()->where('skylight_id', '3002')->count())->toBe(2);
    expect($report->removedFromSkylight)->toBe(1);
});

it('keeps a meal edited here that was deleted in skylight, planning it again', function () {
    $skylight = syncedMeals();
    localMeal('3001', '2026-10-12')->update(['note' => 'Double batch']);
    unset($skylight->meals['3001']);

    $report = syncMeals($skylight);

    $meal = Meal::where('name', 'Spaghetti bolognese')->sole();
    expect($skylight->meals[$meal->skylight_id])->toMatchArray(['note' => 'Double batch', 'instances' => ['2026-10-12']]);
    expect($report->kept)->toBe(1);
});

it('keeps a meal edited in skylight that was deleted here, restoring it', function () {
    $skylight = syncedMeals();
    localMeal('3001', '2026-10-12')->delete();
    $skylight->meals['3001']['note'] = 'Double batch';

    $report = syncMeals($skylight);

    expect(localMeal('3001', '2026-10-12'))->trashed()->toBeFalse()->note->toBe('Double batch');
    expect($report->kept)->toBe(1);
});
