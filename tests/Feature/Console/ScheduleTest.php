<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

it('syncs with skylight every hour', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains($event->command, 'skylight:sync'));

    expect($events)->toHaveCount(1);
    expect($events->first()->expression)->toBe('0 * * * *');
});
