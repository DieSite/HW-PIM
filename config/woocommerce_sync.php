<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Outgoing sync rate limit
    |--------------------------------------------------------------------------
    |
    | Ceiling on how many products may be pushed to WooCommerce per minute.
    | One slot is one product: a parent rug and each of its variants are
    | separate queue jobs, so a rug with 20 variants consumes 21 slots.
    |
    | Enforced by App\Jobs\Middleware\ThrottlesWooCommerceSync. A job that
    | finds the window full is released back onto the queue instead of being
    | dropped, so the sync is slowed down, never skipped.
    |
    */

    'rate_limit' => [
        'per_minute' => (int) env('WOOCOMMERCE_SYNC_PER_MINUTE', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Concurrent syncs
    |--------------------------------------------------------------------------
    |
    | How many products may be talking to WooCommerce at the same time. The
    | per-minute limit above does not stop every queue worker from sending a
    | heavy product write at once, and WordPress then times out on all of
    | them. A job that finds every slot taken is released and tried again
    | after release_after_seconds.
    |
    */

    'concurrency' => [
        'max_concurrent'        => (int) env('WOOCOMMERCE_SYNC_MAX_CONCURRENT', 2),
        'release_after_seconds' => (int) env('WOOCOMMERCE_SYNC_CONCURRENCY_RELEASE_AFTER', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backoff after a failed sync
    |--------------------------------------------------------------------------
    |
    | Seconds to wait before retrying a sync that threw, per retry. WordPress
    | keeps processing a request after our client gave up on it, so a retry
    | that comes back too soon lands on a shop still busy with the first
    | attempt and times out as well.
    |
    */

    'backoff_seconds' => [120, 300, 900],

    /*
    |--------------------------------------------------------------------------
    | Retry deadline
    |--------------------------------------------------------------------------
    |
    | How long a queued sync stays eligible to run, counted from the moment it
    | was dispatched. It bounds the throttled jobs by a deadline instead of an
    | attempt count (see the job classes), because every release by the rate
    | limiter increments the attempt counter and an attempt cap would fail a
    | job that is merely waiting its turn.
    |
    | The default has to cover the worst realistic backlog: at 20 per minute,
    | 24 hours drains 28.800 products.
    |
    */

    'retry_deadline_hours' => (int) env('WOOCOMMERCE_SYNC_RETRY_DEADLINE_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Deadline warning
    |--------------------------------------------------------------------------
    |
    | A sync that only gets its turn with less than this share of its retry
    | window left is reported: the backlog drains slower than the deadline
    | assumes, and the syncs further back will fail without being sent.
    |
    */

    'deadline_warning_remaining' => (float) env('WOOCOMMERCE_SYNC_DEADLINE_WARNING_REMAINING', 0.25),

];
