<?php

use App\Jobs\Middleware\ThrottlesWooCommerceSync;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Redis;
use Webkul\Product\Models\Product as WebkulProduct;
use Webkul\WooCommerce\DTO\ProductBatch;
use Webkul\WooCommerce\Listeners\ProcessProductsToWooCommerce;
use Webkul\WooCommerce\Listeners\SerializedProcessProductsToWooCommerce;

/**
 * The rate-limit window is a Redis bucket shared with everything else running
 * against this Redis, so each test starts from an empty one.
 */
function forgetWooCommerceSyncWindow(): void
{
    Redis::connection()->del(md5(ThrottlesWooCommerceSync::LIMITER));
}

/**
 * A queued job as the middleware sees it: an underlying queue job it can be
 * released back onto, plus a record of the delays it was released with.
 */
function throttleableJob(?object $queueJob = null): object
{
    return new class($queueJob ?? Mockery::mock(QueueJob::class)) {
        /** @var array<int, mixed> */
        public array $releasedAfter = [];

        public function __construct(public ?object $job) {}

        public function release($delay = 0): void
        {
            $this->releasedAfter[] = $delay;
        }
    };
}

beforeEach(function () {
    forgetWooCommerceSyncWindow();
});

afterEach(function () {
    forgetWooCommerceSyncWindow();
});

it('caps outgoing WooCommerce product syncs at twenty per minute by default', function () {
    expect((int) config('woocommerce_sync.rate_limit.per_minute'))->toBe(20);
});

it('lets the configured number of products through per minute', function () {
    config(['woocommerce_sync.rate_limit.per_minute' => 3]);

    $middleware = new ThrottlesWooCommerceSync();
    $ran = 0;

    $jobs = [];

    foreach (range(1, 5) as $index) {
        $jobs[$index] = throttleableJob();
        $middleware->handle($jobs[$index], function () use (&$ran) {
            $ran++;
        });
    }

    expect($ran)->toBe(3, 'Only the configured number of products may reach WooCommerce inside one window.');

    foreach ([1, 2, 3] as $index) {
        expect($jobs[$index]->releasedAfter)->toBeEmpty();
    }
});

/**
 * The whole point of releasing rather than failing: a rug that arrives while
 * the window is full is not skipped, it waits for the next one. A dropped
 * sync would leave the shop showing stale prices with nothing to show for it.
 */
it('releases a product back onto the queue instead of dropping it when the window is full', function () {
    config(['woocommerce_sync.rate_limit.per_minute' => 1]);

    $middleware = new ThrottlesWooCommerceSync();
    $next = fn () => null;

    $middleware->handle(throttleableJob(), $next);

    $throttled = throttleableJob();
    $ran = false;

    $middleware->handle($throttled, function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeFalse()
        ->and($throttled->releasedAfter)->toHaveCount(1)
        ->and($throttled->releasedAfter[0])->toBeGreaterThan(0, 'The release delay must carry the job into the next window.')
        ->and($throttled->releasedAfter[0])->toBeLessThanOrEqual(63, 'A one-minute window may never park a job for longer than that window plus the framework\'s margin.');
});

/**
 * SerializedProcessProductsToWooCommerce runs its inner job through
 * dispatchSync(), and SyncJob::release() does not re-queue anything — a
 * throttle there would silently discard the sync. The outer queued job has
 * already spent this product's slot anyway.
 */
it('never throttles the inner synchronous dispatch, whose release would discard the job', function () {
    config(['woocommerce_sync.rate_limit.per_minute' => 1]);

    $middleware = new ThrottlesWooCommerceSync();
    $middleware->handle(throttleableJob(), fn () => null);

    $syncDispatch = throttleableJob(Mockery::mock(SyncJob::class));
    $ran = false;

    $middleware->handle($syncDispatch, function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeTrue()
        ->and($syncDispatch->releasedAfter)->toBeEmpty();
});

/**
 * Both paths that push a product to WooCommerce must share the budget: the
 * chain built by ProductService (parent job plus one job per variant) and the
 * catalog.product.*.after listener that queues the processor directly.
 */
it('throttles every job that pushes a product to WooCommerce', function () {
    $jobs = [
        SerializedProcessProductsToWooCommerce::class => new SerializedProcessProductsToWooCommerce(new WebkulProduct()),
        ProcessProductsToWooCommerce::class           => new ProcessProductsToWooCommerce(ProductBatch::fromProductArray(['sku' => 'SKU-1'])),
    ];

    foreach ($jobs as $class => $job) {
        $middleware = array_map(fn (object $m) => $m::class, $job->middleware());

        expect(in_array(ThrottlesWooCommerceSync::class, $middleware, true))->toBeTrue(
            "[{$class}] writes a product to WooCommerce, so it must spend a slot of the outgoing rate limit."
        );
    }
});

/**
 * A throttled job is released, and a release increments the attempt counter
 * without the job ever having run. Under an attempt cap a rug queued behind a
 * bulk export would be failed for waiting its turn, so these jobs have to be
 * bounded by a deadline instead — the worker skips the attempt check entirely
 * while retryUntil() is in the future. {@see tests/Unit/QueueTimingInvariantsTest.php}
 */
it('bounds the throttled jobs by a deadline rather than an attempt count', function () {
    $jobs = [
        SerializedProcessProductsToWooCommerce::class => new SerializedProcessProductsToWooCommerce(new WebkulProduct()),
        ProcessProductsToWooCommerce::class           => new ProcessProductsToWooCommerce(ProductBatch::fromProductArray(['sku' => 'SKU-1'])),
    ];

    foreach ($jobs as $class => $job) {
        expect((int) ($job->tries ?? 0))->toBe(
            0,
            "[{$class}] is rate limited, so an attempt cap would fail it for being released while waiting for a free slot."
        );

        expect($job->retryUntil()->getTimestamp())->toBeGreaterThan(
            now()->getTimestamp(),
            "[{$class}] must keep a retry deadline in the future, or every attempt fails immediately."
        );

        expect($job->maxExceptions ?? null)->toBeGreaterThan(
            0,
            "[{$class}] must cap genuine failures with \$maxExceptions, which only advances when handle() actually threw."
        );
    }
});

/**
 * The deadline exists so a throttled sync eventually runs; it must therefore
 * outlast draining a realistic backlog at the configured rate.
 */
it('gives a throttled sync a deadline long enough to drain a full backlog', function () {
    $drainableProducts = (int) config('woocommerce_sync.rate_limit.per_minute')
        * 60
        * (int) config('woocommerce_sync.retry_deadline_hours');

    expect($drainableProducts)->toBeGreaterThanOrEqual(
        20000,
        'At the configured rate the retry deadline must still cover a catalog-wide re-export, or a queued sync expires before its turn.'
    );
});
