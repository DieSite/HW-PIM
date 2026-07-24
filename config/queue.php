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

        /**
         * retry_after must stay strictly above the timeout of every job and
         * supervisor on the connection (enforced by
         * tests/Unit/QueueTimingInvariantsTest.php): when a running job
         * crosses retry_after, Redis re-reserves it mid-flight and the extra
         * attempt surfaces as MaxAttemptsExceededException.
         */
        'redis' => [
            'driver'      => 'redis',
            'connection'  => 'default',
            'queue'       => env('REDIS_QUEUE', 'default'),
            'retry_after' => 900,
            'block_for'   => null,
        ],

        /**
         * Hordeuren competitor analysis (RunHordeurenAnalysisJob and the
         * per-competitor scrape batch it dispatches) lives on its own
         * connection so its visibility timeout does not apply to every other
         * job. retry_after must exceed the largest job timeout on the queue
         * (the toolchain-install orchestrator, 3000s). Served by the dedicated
         * Horizon supervisor "supervisor-hordeuren".
         */
        'redis-hordeuren' => [
            'driver'      => 'redis',
            'connection'  => 'default',
            'queue'       => 'hordeuren',
            'retry_after' => 3600,
            'block_for'   => null,
        ],

        /**
         * The De Munk voorraad import (ImportVoorraadDeMunkJob orchestrator,
         * the per-collection fetch batch and the final apply job) crawls the
         * dealer portal and runs well past the shared "default" queue's
         * retry_after. Its own connection carries a retry_after comfortably
         * above the largest job timeout on the queue (900s). Served by the
         * dedicated Horizon supervisor "supervisor-demunk".
         */
        'redis-demunk' => [
            'driver'      => 'redis',
            'connection'  => 'default',
            'queue'       => 'demunk',
            'retry_after' => 3600,
            'block_for'   => null,
        ],

        /**
         * Generic slot for long-running jobs (currently BulkEditProductsJob,
         * $timeout 3600) so they never sit on the shared "default" queue whose
         * short retry_after would re-reserve them mid-flight. Served by the
         * dedicated Horizon supervisor "supervisor-long".
         */
        'redis-long' => [
            'driver'      => 'redis',
            'connection'  => 'default',
            'queue'       => 'long',
            'retry_after' => 7200,
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
