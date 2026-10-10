<?php

use App\Services\MacHelper\DriveEstimate;
use App\Services\MacHelper\MacDirections;
use App\Services\MacHelper\MacHelperException;
use Carbon\CarbonImmutable;

it('asks Apple Maps for a drive that arrives by the given time', function () {
    $captured = fakeMacHelper(drive: ['minutes' => 22, 'miles' => 11.4, 'traffic_aware' => true]);

    $estimate = app(MacDirections::class)->drive('1 Main St, Westerville, OH', '2 Oak Ave, Columbus, OH', CarbonImmutable::parse('2026-10-13 18:00', 'America/New_York'));

    expect($estimate)->toEqual(new DriveEstimate(minutes: 22, miles: 11.4, trafficAware: true))
        ->and($captured->runs[0])->toBe([
            'drive', '1 Main St, Westerville, OH', '2 Oak Ave, Columbus, OH', '2026-10-13T18:00:00-04:00',
        ]);
});

it('reports when the estimate is not traffic aware', function () {
    fakeMacHelper(drive: ['minutes' => 9, 'miles' => 3.0, 'traffic_aware' => false]);

    $estimate = app(MacDirections::class)->drive('Home', 'School', CarbonImmutable::now());

    expect($estimate->trafficAware)->toBeFalse();
});

it('reports what the helper says went wrong, such as an address it cannot find', function () {
    fakeMacHelper(error: 'Couldn\'t find the address "Nowhere".');

    app(MacDirections::class)->drive('Home', 'Nowhere', CarbonImmutable::now());
})->throws(MacHelperException::class, 'Couldn\'t find the address');

it('rejects an answer that is not a drive estimate', function () {
    fakeMacHelper(drive: ['unexpected' => true]);

    app(MacDirections::class)->drive('Home', 'School', CarbonImmutable::now());
})->throws(MacHelperException::class, 'something unexpected');

it('explains how to install the helper when it is missing', function () {
    config(['mac_helper.app_path' => sys_get_temp_dir().'/missing-'.bin2hex(random_bytes(4)).'.app']);

    app(MacDirections::class)->drive('Home', 'School', CarbonImmutable::now());
})->throws(MacHelperException::class, 'php artisan mac-helper:install');
