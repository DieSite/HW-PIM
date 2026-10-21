<?php

namespace App\Jobs\Middleware;

use App\Enums\WooCommerceAssumption;
use App\Services\WooCommerce\WooCommerceAssumptionMonitor;
use Carbon\CarbonInterval;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Middleware\RateLimitedWithRedis;
use Illuminate\Redis\Connections\Connection;
use Webkul\WooCommerce\Listeners\SerializedProcessProductsToWooCommerce;

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
 * A per-minute budget alone still lets every worker hit the shop at once, and
 * a single product write can take close to a minute, so on top of it a Redis
 * funnel caps how many syncs talk to WooCommerce at the same time. A job that
 * finds the funnel full is released as well.
 *
 * A job that arrives with the window full is RELEASED back onto the queue with
 * the delay until the window rolls over, never dropped. That is also why the
 * jobs carrying this middleware bound their retries with retryUntil() instead
 * of $tries: a release increments the attempt counter without the job ever
 * having run, so an attempt cap would fail syncs for waiting their turn.
 * {@see SerializedProcessProductsToWooCommerce}
 */
class ThrottlesWooCommerceSync extends RateLimitedWithRedis
{
    /**
     * Name of the limiter registered in AppServiceProvider::boot().
     */
    public const LIMITER = 'woocommerce-sync';

    /**
     * Redis key of the funnel capping concurrent syncs.
     */
    public const FUNNEL = 'woocommerce-sync-concurrency';

    /**
     * Seconds a funnel slot outlives the job's own timeout, so the slot of a
     * worker killed mid-sync frees itself instead of blocking the funnel.
     */
    private const FUNNEL_SLOT_MARGIN_SECONDS = 60;

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

        return $this->redis()->funnel(self::FUNNEL)
            ->limit((int) config('woocommerce_sync.concurrency.max_concurrent'))
            ->releaseAfter((int) ($job->timeout ?? 600) + self::FUNNEL_SLOT_MARGIN_SECONDS)
            ->block(0)
            ->then(
                fn () => parent::handle($job, function ($job) use ($queueJob, $next) {
                    $this->verifyDeadlineHeadroom($queueJob);

                    return $next($job);
                }),
                fn () => $job->release((int) config('woocommerce_sync.concurrency.release_after_seconds'))
            );
    }

    /**
     * The throttle only works if a queued sync gets its turn before its retry
     * deadline; past it the sync fails without ever reaching the shop. How
     * fast the backlog drains depends on how slow the shop is, so every sync
     * that gets its turn checks how much of its window it used up waiting.
     */
    private function verifyDeadlineHeadroom(QueueJob $queueJob): void
    {
        $payload = $queueJob->payload();
        $deadline = $payload['retryUntil'] ?? null;
        $queuedAt = $payload['createdAt'] ?? null;

        if (! $deadline || ! $queuedAt || $deadline <= $queuedAt) {
            Container::getInstance()->make(WooCommerceAssumptionMonitor::class)->violated(
                WooCommerceAssumption::BacklogDrainsBeforeDeadline,
                sprintf('It cannot be checked for %s: its queue payload carries no usable createdAt/retryUntil.', $payload['displayName'] ?? 'a sync'),
                ['createdAt' => $queuedAt, 'retryUntil' => $deadline]
            );

            return;
        }

        $remaining = ($deadline - now()->getTimestamp()) / ($deadline - $queuedAt);

        if ($remaining >= (float) config('woocommerce_sync.deadline_warning_remaining')) {
            return;
        }

        Container::getInstance()->make(WooCommerceAssumptionMonitor::class)->violated(
            WooCommerceAssumption::BacklogDrainsBeforeDeadline,
            sprintf(
                '%s got its turn with %d%% of its retry window left (%s remaining).',
                $payload['displayName'] ?? 'A sync',
                max(0, (int) round($remaining * 100)),
                CarbonInterval::seconds(max(0, $deadline - now()->getTimestamp()))->cascade()->forHumans(['parts' => 2])
            ),
            ['queuedAt' => $queuedAt, 'retryUntil' => $deadline, 'attempts' => $queueJob->attempts()]
        );
    }

    private function redis(): Connection
    {
        return Container::getInstance()->make(Redis::class)->connection($this->connectionName);
    }
}
