<?php

namespace App\Services;

use RuntimeException;

class AccountEnvironment
{
    /**
     * Identify the OS account PHP is running as. Web servers such as
     * `php artisan serve` strip these from the environment, but the claude
     * CLI needs all three to find its login in the macOS keychain, and MCP
     * servers need HOME for their token caches.
     *
     * @return array{HOME: string, USER: string, LOGNAME: string}
     *
     * @throws RuntimeException
     */
    public static function current(): array
    {
        $account = posix_getpwuid(posix_geteuid());

        if ($account === false) {
            throw new RuntimeException('Could not look up the OS account PHP is running as.');
        }

        return [
            'HOME' => $account['dir'],
            'USER' => $account['name'],
            'LOGNAME' => $account['name'],
        ];
    }
}
