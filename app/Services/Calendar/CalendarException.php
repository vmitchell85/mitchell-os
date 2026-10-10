<?php

namespace App\Services\Calendar;

use RuntimeException;

class CalendarException extends RuntimeException
{
    public static function notInstalled(): self
    {
        return new self('The calendar helper isn\'t installed yet. Run `php artisan calendar:install` on the Mac that runs the app.');
    }
}
