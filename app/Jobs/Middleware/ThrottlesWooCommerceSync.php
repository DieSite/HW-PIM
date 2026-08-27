<?php

namespace App\Jobs\Middleware;

use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Middleware\RateLimitedWithRedis;

/**
 * Cap how fast products leave for WooCommerce.
 *
 * A bulk sync (a brand re-export, a price backfill, a bulk edit) otherwise
 * hands the shop as many product writes as the single queue worker can make
 * HTTP calls, and both environments crawl. One slot is one product — a parent
 * rug and each of its variants are separate queue jobs — so the configured
 * ceiling counts rugs including their variants.
 *
 * The window is a Redis duration bucket, so the limit is global: every worker
 * process, queue and dispatch path shares the same budget.
 *
 * A job that arrives with the window full is RELEASED back onto the queue with
 * the delay until the window rolls over, never dropped. That is also why the
 * jobs carrying this middleware bound their retries with retryUntil() instead
 * of $tries: a release increments the attempt counter without the job ever
 * having run, so an attempt cap would fail syncs for waiting their turn.
 * {@see \Webkul\WooCommerce\Listeners\SerializedProcessProductsToWooCommerce}
 */
class ThrottlesWooCommerceSync extends RateLimitedWithRedis
{
    /**
     * Name of the limiter registered in AppServiceProvider::boot().
     */
    public const LIMITER = 'woocommerce-sync';

    public function __construct()
    {
        parent::__construct(self::LIMITER);
    }

    /**
     * Only a job that can actually be released may be throttled.
     *
     * SerializedProcessProductsToWooCommerce runs its inner
     * ProcessProductsToWooCommerce through dispatchSync(), and a SyncJob's
     * release() is a no-op: the job would be silently discarded instead of
     * retried. The outer queued job already spent this product's slot, so
     * running through here again would double-count it anyway.
     */
    public function handle($job, $next): mixed
    {
        $queueJob = $job->job ?? null;

        if (! $queueJob instanceof QueueJob || $queueJob instanceof SyncJob) {
            return $next($job);
        }

        return parent::handle($job, $next);
    }
}
