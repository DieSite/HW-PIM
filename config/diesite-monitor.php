<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    */

    'enabled' => env('DIESITE_MONITOR_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Shared key
    |--------------------------------------------------------------------------
    | The dashboard sends this value in the X-DieSite-Monitor-Key header.
    | The endpoint returns 403 while this is unset.
    */

    'key' => env('DIESITE_MONITOR_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Route
    |--------------------------------------------------------------------------
    */

    'route_prefix' => env('DIESITE_MONITOR_PREFIX', 'api'),

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    | Queue names to report. When empty, every queue found in the jobs table
    | is reported (plus "default" so an empty install still shows up).
    |
    | HW-PIM runs on Redis + Horizon: these are the queues the Horizon
    | supervisors in config/horizon.php serve. Pending counts are read from
    | Redis by App\Monitor\HorizonQueueStats (bound in AppServiceProvider).
    */

    'queues' => ['default', 'bolcom', 'hordeuren', 'demunk', 'long', 'ai'],

    /*
    |--------------------------------------------------------------------------
    | Metric resolvers
    |--------------------------------------------------------------------------
    | Invokable classes (or container-resolvable callables). Each runs inside
    | the request and its return value is included in the payload. Leave null
    | to omit the metric.
    |
    | orders/revenue resolvers must return ['today' => x, 'yesterday' => y].
    |
    | visitors ("live bezoekers" on the board) must return a plain int: the
    | number of visitors currently active on the site right now, not a daily
    | total. "Currently active" means they did something (page load, request)
    | within roughly the last 5 minutes — a rolling concurrent-visitor count,
    | the same shape as what "X people online" widgets show. Return 0 (never
    | null) if you have no data source for this yet. See the README for a
    | ready-to-use resolver against Laravel's database session driver.
    |
    | highlights must return an array of
    | ['label' => ..., 'value' => ..., 'format' => 'number'|'eur'] entries
    | (used for the spotlight on SaaS sites).
    */

    'resolvers' => [
        'orders' => null,
        'revenue' => null,
        'visitors' => \App\Monitor\ActiveAdmins::class,
        'highlights' => \App\Monitor\PimHighlights::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Deploys
    |--------------------------------------------------------------------------
    | History is a JSON file read/written through the given filesystem disk
    | (defaults to "local", i.e. storage/app/... ) rather than a raw path in
    | the project root. Run `php artisan diesite-monitor:record-deploy` as a
    | deployment hook to append to it — see the README for wiring this into
    | Envoyer. Using the "local" disk means this persists across releases on
    | Envoyer/Forge automatically, since storage/ is shared by default; no
    | extra "shared file" configuration needed.
    |
    | When the file is empty/missing, the package falls back to reporting the
    | current git tag/commit of the release with its deploy time.
    */

    'deploys' => [
        'disk' => env('DIESITE_MONITOR_DEPLOYS_DISK', 'local'),
        'path' => env('DIESITE_MONITOR_DEPLOYS_PATH', 'diesite-monitor/deploys.json'),
        'from_git' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Important events
    |--------------------------------------------------------------------------
    | Events recorded with Monitor::positive()/negative() are buffered in a
    | JSON file on this disk (the newest "keep" entries) and served from
    | GET /api/diesite-monitor/events. The dashboard polls that every ~10s and
    | shows new ones as a fullscreen alert with a sound.
    */

    'events' => [
        'disk' => env('DIESITE_MONITOR_EVENTS_DISK', 'local'),
        'path' => env('DIESITE_MONITOR_EVENTS_PATH', 'diesite-monitor/events.json'),
        'keep' => 100,
    ],
];
