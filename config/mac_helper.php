<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mac Helper
    |--------------------------------------------------------------------------
    |
    | Some things only the Mac can do: reading macOS Calendar (so any account
    | synced to this Mac, like Google, can be used) and asking Apple Maps for
    | traffic-aware drive times. A small background app, built by
    | `php artisan mac-helper:install`, does them; macOS asks once for
    | permission on its behalf.
    |
    */

    'app_path' => env('MAC_HELPER_APP', storage_path('app/mac-helper/Mitchell OS Helper.app')),

    'source' => resource_path('native/mac-helper.swift'),

    'info_plist' => resource_path('native/mac-helper-Info.plist'),

    'timeout' => (int) env('MAC_HELPER_TIMEOUT', 30),

];
