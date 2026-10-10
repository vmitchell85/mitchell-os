<?php

return [

    /*
    |--------------------------------------------------------------------------
    | macOS Calendar Helper
    |--------------------------------------------------------------------------
    |
    | Calendars are read from macOS Calendar, so any account synced to this
    | Mac (Google, iCloud, Exchange) can be used. A small background app,
    | built by `php artisan calendar:install`, reads them; macOS asks once
    | for permission on its behalf.
    |
    */

    'app_path' => env('CALENDAR_HELPER_APP', storage_path('app/calendar/Mitchell OS Calendar.app')),

    'source' => resource_path('native/calendar-helper.swift'),

    'info_plist' => resource_path('native/calendar-helper-Info.plist'),

    'timeout' => (int) env('CALENDAR_HELPER_TIMEOUT', 30),

];
