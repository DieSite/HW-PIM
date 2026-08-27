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

];
