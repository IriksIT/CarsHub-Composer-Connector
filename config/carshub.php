<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | API credentials
    |--------------------------------------------------------------------------
    |
    | Your CarsHub API key and the slug of the crew this site represents.
    | Both values are shown in Crew Settings → Website Sync on carshub.nl.
    |
    */

    'api_key' => env('CARSHUB_API_KEY'),

    'crew_slug' => env('CARSHUB_CREW_SLUG'),

    'api_base_url' => env('CARSHUB_API_URL', 'https://carshub.nl/api'),

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Data is stored as JSON files inside storage/carshub/.  TTLs are in
    | seconds.  Pages and settings are relatively static so they refresh once
    | a day.  Events and member lists change more often, so they refresh hourly.
    |
    */

    'cache' => [
        'path' => env('CARSHUB_CACHE_PATH', 'carshub'),   // relative to storage_path()

        'ttl' => [
            'pages'    => env('CARSHUB_TTL_PAGES',    86400),  // 24 h
            'settings' => env('CARSHUB_TTL_SETTINGS', 86400),  // 24 h
            'events'   => env('CARSHUB_TTL_EVENTS',   3600),   //  1 h
            'members'  => env('CARSHUB_TTL_MEMBERS',  3600),   //  1 h
            'cars'     => env('CARSHUB_TTL_CARS',     3600),   //  1 h
            'stats'    => env('CARSHUB_TTL_STATS',    3600),   //  1 h
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Boot sync
    |--------------------------------------------------------------------------
    |
    | When true, the service provider dispatches a sync job on boot if any
    | cache files are missing (first install or after cache:clear).  Stale-but-
    | present cache is refreshed in the background via the scheduler instead.
    |
    */

    'sync_on_boot' => env('CARSHUB_SYNC_ON_BOOT', true),

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | The page slugs to fetch from the CarsHub pages API.  These map directly
    | to the page keys configured in Crew Settings → Website Sync.
    |
    */

    'pages' => ['home', 'events', 'members', 'cars', 'about'],

    /*
    |--------------------------------------------------------------------------
    | HTTP timeout
    |--------------------------------------------------------------------------
    */

    'timeout' => env('CARSHUB_TIMEOUT', 10),

];
