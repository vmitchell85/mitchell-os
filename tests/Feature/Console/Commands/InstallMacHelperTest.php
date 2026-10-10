<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

it('builds the Mac helper app, signs it and checks calendar access', function () {
    $app = sys_get_temp_dir().'/mac-helper-install-'.bin2hex(random_bytes(4)).'/Mitchell OS Helper.app';
    config(['mac_helper.app_path' => $app]);
    $commands = [];

    Process::preventStrayProcesses();
    Process::fake([
        "'swiftc' *" => function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;
            touch($process->command[array_search('-o', $process->command, true) + 1]);

            return Process::describe();
        },
        "'codesign' *" => function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;

            return Process::describe();
        },
        "'open' '-W' *" => function (PendingProcess $process) {
            file_put_contents($process->command[array_search('--stdout', $process->command, true) + 1], json_encode([
                ['id' => 'WORK-1', 'title' => 'Work', 'account' => 'vince@example.com', 'color' => null],
            ]));

            return Process::describe();
        },
    ]);

    $this->artisan('mac-helper:install')
        ->expectsTable(['Account', 'Calendar'], [['vince@example.com', 'Work']])
        ->assertSuccessful();

    expect(file_get_contents("{$app}/Contents/Info.plist"))->toContain('NSCalendarsFullAccessUsageDescription');
    expect(is_file("{$app}/Contents/MacOS/mac-helper"))->toBeTrue();
    expect($commands[1])->toBe(['codesign', '--force', '--sign', '-', dirname($app).'/building/Mitchell OS Helper.app']);
});

it('reports when calendar access is not allowed', function () {
    fakeMacHelper(error: 'Calendar access was not allowed.');

    $this->artisan('mac-helper:install')
        ->expectsOutputToContain('Calendar access was not allowed.')
        ->assertFailed();
});
