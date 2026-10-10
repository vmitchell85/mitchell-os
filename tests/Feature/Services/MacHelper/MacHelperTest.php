<?php

use App\Services\MacHelper\MacHelper;
use App\Services\MacHelper\MacHelperException;

it('uses the output when open only warns that it lost the kevent race', function () {
    $captured = fakeMacHelper(calendars: [['id' => 'A1']], raceFailures: 1);

    expect(app(MacHelper::class)->run(['calendars']))->toBe([['id' => 'A1']])
        ->and($captured->runs)->toHaveCount(1);
});

it('times out when the output never appears after the kevent race', function () {
    config(['mac_helper.timeout' => 1]);
    fakeMacHelper(raceFailures: 1, raceDropsOutput: true);

    app(MacHelper::class)->run(['calendars']);
})->throws(MacHelperException::class, 'didn\'t answer in time');

it('reports an error the helper wrote even after the kevent race', function () {
    fakeMacHelper(error: 'Calendar access is turned off.', raceFailures: 1);

    app(MacHelper::class)->run(['calendars']);
})->throws(MacHelperException::class, 'Calendar access is turned off.');

it('does not wait for output after a failure that is not the race', function () {
    $captured = fakeMacHelper(error: 'Calendar access is turned off.');

    expect(fn () => app(MacHelper::class)->run(['calendars']))->toThrow(MacHelperException::class, 'Calendar access is turned off.')
        ->and($captured->runs)->toHaveCount(1);
});
