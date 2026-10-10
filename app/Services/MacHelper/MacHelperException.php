<?php

namespace App\Services\MacHelper;

use RuntimeException;

class MacHelperException extends RuntimeException
{
    public static function notInstalled(): self
    {
        return new self('The Mac helper isn\'t installed yet. Run `php artisan mac-helper:install` on the Mac that runs the app.');
    }
}
