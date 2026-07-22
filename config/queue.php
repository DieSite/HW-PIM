<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue API supports an assortment of back-ends via a single
    | API, giving you convenient access to each back-end using the same
    | syntax for every one. Here you may define a default connection.
    |
    */

    'default' => env('QUEUE_DRIVER', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection information for each server that
    | is used by your application. A default configuration has been added
    | for each back-end shipped with Laravel. You are free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver'      => 'database',
            'table'       => 'jobs',
            'queue'       => 'default',
            'retry_after' => 660,
        ],

        'beanstalkd' => [
            'driver'      => 'beanstalkd',
            'host'        => 'localhost',
            'queue'       => 'default',
            'retry_after' => 660,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key'    => env('SQS_KEY', 'your-public-key'),
            'secret' => env('SQS_SECRET', 'your-secret-key'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue'  => env('SQS_QUEUE', 'your-queue-name'),
            'region' => env('SQS_REGION', 'us-east-1'),
        ],

        'redis' => [
            'driver'      => 'redis',
            'connection'  => 'default',
            'queue'       => env('REDIS_QUEUE', 'default'),
            'retry_after' => 660,
            'block_for'   => null,
        ],

        /**
         * Long-running competitor scrapes (RunHordeurenAnalysisJob) live on
         * their own connection so their multi-hour visibility timeout does not
         * apply to every other job. retry_after must exceed the job's worst-
         * case wall time (npm/browser install + max_passes × per-pass timeout;
         * see the job's $timeout). Served by the dedicated Horizon supervisor
         * "supervisor-hordeuren" listening on the "hordeuren" queue.
         */
        'redis-hordeuren' => [
            'driver'      => 'redis',
            'connection'  => 'default',
            'queue'       => 'hordeuren',
            'retry_after' => 21600,
            'block_for'   => null,
        ],

        /**
         * ImportVoorraadDeMunkJob crawls the De Munk dealer portal (dozens of
         * collections/qualities, ~8 sequential HTTP calls each) and can run
         * well past the shared "default" queue's 660s retry_after, causing
         * Redis to re-reserve the job mid-flight and fail it with
         * MaxAttemptsExceededException. Its own connection carries a
         * retry_after comfortably above the job's $timeout (1800s). Served by
         * the dedicated Horizon supervisor "supervisor-demunk" on the
         * "demunk" queue.
         */
        'redis-demunk' => [
            'driver'      => 'redis',
            'connection'  => 'default',
            'queue'       => 'demunk',
            'retry_after' => 3600,
            'block_for'   => null,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control which database and table are used to store the jobs that
    | have failed. You may change them to any database / table you wish.
    |
    */

    'failed' => [
        'driver'   => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table'    => 'failed_jobs',
    ],

];
