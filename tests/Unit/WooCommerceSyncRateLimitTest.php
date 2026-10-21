<?php

use App\Enums\WooCommerceAssumption;
use App\Jobs\Middleware\ThrottlesWooCommerceSync;
use App\Services\WooCommerce\WooCommerceAssumptionMonitor;
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

    foreach (range(1, 5) as $slot) {
        Redis::connection()->del(ThrottlesWooCommerceSync::FUNNEL.$slot);
    }
}

/**
 * A queued job as the middleware sees it: an underlying queue job it can be
 * released back onto, plus a record of the delays it was released with.
 *
 * @param  array<string, mixed>  $payload
 */
function throttleableJob(?object $queueJob = null, array $payload = []): object
{
    if (! $queueJob) {
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('payload')->andReturn($payload);
        $queueJob->shouldReceive('attempts')->andReturn(1);
    }

    return new class($queueJob)
    {
        /** @var array<int, mixed> */
        public array $releasedAfter = [];

        public ?int $timeout = null;

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

/**
 * The per-minute budget does not stop every worker from writing to the shop
 * at once, and a heavy product write can take close to a minute; with all
 * workers doing that together WordPress times out on every one of them.
 */
it('releases a sync while the configured number of syncs is already talking to WooCommerce', function () {
    config(['woocommerce_sync.concurrency.max_concurrent' => 1]);

    $middleware = new ThrottlesWooCommerceSync();
    $waiting = throttleableJob();
    $waitingRan = false;

    $middleware->handle(throttleableJob(), function () use ($middleware, $waiting, &$waitingRan) {
        $middleware->handle($waiting, function () use (&$waitingRan) {
            $waitingRan = true;
        });
    });

    expect($waitingRan)->toBeFalse('A second sync may not reach WooCommerce while the only slot is taken.')
        ->and($waiting->releasedAfter)->toBe([15]);
});

it('frees the concurrency slot once a sync has finished', function () {
    config(['woocommerce_sync.concurrency.max_concurrent' => 1]);

    $middleware = new ThrottlesWooCommerceSync();
    $ran = 0;

    foreach (range(1, 3) as $index) {
        $middleware->handle(throttleableJob(), function () use (&$ran) {
            $ran++;
        });
    }

    expect($ran)->toBe(3);
});

it('frees the concurrency slot when a sync throws', function () {
    config(['woocommerce_sync.concurrency.max_concurrent' => 1]);

    $middleware = new ThrottlesWooCommerceSync();

    expect(fn () => $middleware->handle(throttleableJob(), fn () => throw new RuntimeException('WooCommerce timed out')))
        ->toThrow(RuntimeException::class);

    $ran = false;
    $middleware->handle(throttleableJob(), function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeTrue();
});

/**
 * WordPress keeps processing a request after our client gave up on it, so a
 * retry one minute later lands on a shop still busy with the first attempt.
 */
it('waits at least two minutes before retrying a failed sync', function () {
    $jobs = [
        SerializedProcessProductsToWooCommerce::class => new SerializedProcessProductsToWooCommerce(new WebkulProduct()),
        ProcessProductsToWooCommerce::class           => new ProcessProductsToWooCommerce(ProductBatch::fromProductArray(['sku' => 'SKU-1'])),
    ];

    foreach ($jobs as $class => $job) {
        $backoff = $job->backoff();

        expect($backoff[0])->toBeGreaterThanOrEqual(120, "[{$class}] retries too soon after a WooCommerce timeout.")
            ->and($backoff)->toBe(collect($backoff)->sort()->values()->all(), "[{$class}] must back off longer on every retry.");
    }
});

it('lets two syncs talk to WooCommerce at the same time by default and retries a waiting one after fifteen seconds', function () {
    expect((int) config('woocommerce_sync.concurrency.max_concurrent'))->toBe(2)
        ->and((int) config('woocommerce_sync.concurrency.release_after_seconds'))->toBe(15);
});

it('lets as many syncs run side by side as configured', function () {
    config(['woocommerce_sync.concurrency.max_concurrent' => 2]);

    $middleware = new ThrottlesWooCommerceSync();
    $second = throttleableJob();
    $third = throttleableJob();
    $ran = [];

    $middleware->handle(throttleableJob(), function () use ($middleware, $second, $third, &$ran) {
        $ran[] = 'first';

        $middleware->handle($second, function () use ($middleware, $third, &$ran) {
            $ran[] = 'second';

            $middleware->handle($third, function () use (&$ran) {
                $ran[] = 'third';
            });
        });
    });

    expect($ran)->toBe(['first', 'second'])
        ->and($second->releasedAfter)->toBeEmpty()
        ->and($third->releasedAfter)->toBe([15]);
});

/**
 * A job turned away by the per-minute window never reached WooCommerce, so it
 * must not keep a concurrency slot occupied either.
 */
it('frees the concurrency slot of a sync the per-minute window turned away', function () {
    config(['woocommerce_sync.concurrency.max_concurrent' => 1, 'woocommerce_sync.rate_limit.per_minute' => 1]);

    $middleware = new ThrottlesWooCommerceSync();
    $middleware->handle(throttleableJob(), fn () => null);

    $turnedAway = throttleableJob();
    $middleware->handle($turnedAway, fn () => null);

    config(['woocommerce_sync.rate_limit.per_minute' => 100]);
    $ran = false;
    $middleware->handle(throttleableJob(), function () use (&$ran) {
        $ran = true;
    });

    expect($turnedAway->releasedAfter)->toHaveCount(1)
        ->and($turnedAway->releasedAfter[0])->not->toBe(15, 'It was turned away by the window, not by the funnel.')
        ->and($ran)->toBeTrue();
});

/**
 * A worker killed mid-sync (deploy, OOM) never releases its slot. The slot
 * has to expire on its own, but not before a sync could still be running.
 */
it('lets the slot of a killed worker expire shortly after the job timeout', function () {
    config(['woocommerce_sync.concurrency.max_concurrent' => 1]);

    $job = throttleableJob();
    $job->timeout = 600;
    $slotTtl = null;

    (new ThrottlesWooCommerceSync())->handle($job, function () use (&$slotTtl) {
        $slotTtl = Redis::connection()->ttl(ThrottlesWooCommerceSync::FUNNEL.'1');
    });

    expect($slotTtl)->toBeGreaterThan(600)->toBeLessThanOrEqual(660);
});

it('never holds back the inner synchronous dispatch, even with every slot taken', function () {
    config(['woocommerce_sync.concurrency.max_concurrent' => 1]);

    $middleware = new ThrottlesWooCommerceSync();
    $innerRan = false;

    $middleware->handle(throttleableJob(), function () use ($middleware, &$innerRan) {
        $middleware->handle(throttleableJob(Mockery::mock(SyncJob::class)), function () use (&$innerRan) {
            $innerRan = true;
        });
    });

    expect($innerRan)->toBeTrue();
});

/**
 * A sync waiting so long it nearly misses its retry deadline means the
 * backlog drains slower than the deadline assumes; the syncs behind it will
 * fail without ever reaching the shop unless someone steps in.
 */
it('reports a sync that only gets its turn near the end of its retry window', function () {
    $this->freezeTime();
    $monitor = $this->spy(WooCommerceAssumptionMonitor::class);
    $ran = false;

    (new ThrottlesWooCommerceSync())->handle(
        throttleableJob(payload: [
            'displayName' => SerializedProcessProductsToWooCommerce::class,
            'createdAt'   => now()->subHours(20)->getTimestamp(),
            'retryUntil'  => now()->addHours(4)->getTimestamp(),
        ]),
        function () use (&$ran) {
            $ran = true;
        }
    );

    expect($ran)->toBeTrue('The check only reports; the sync itself still runs.');

    $monitor->shouldHaveReceived('violated')->with(
        WooCommerceAssumption::BacklogDrainsBeforeDeadline,
        Mockery::pattern('/SerializedProcessProductsToWooCommerce got its turn with 17% of its retry window left \(4 hours remaining\)/'),
        Mockery::any()
    )->once();
});

it('reports nothing about a sync that gets its turn with time to spare', function () {
    $monitor = $this->spy(WooCommerceAssumptionMonitor::class);

    (new ThrottlesWooCommerceSync())->handle(
        throttleableJob(payload: ['createdAt' => now()->subHours(17)->getTimestamp(), 'retryUntil' => now()->addHours(7)->getTimestamp()]),
        fn () => null
    );

    $monitor->shouldNotHaveReceived('violated');
});

it('only checks the deadline of a sync that actually gets its turn', function () {
    config(['woocommerce_sync.rate_limit.per_minute' => 1]);
    $monitor = $this->spy(WooCommerceAssumptionMonitor::class);
    $middleware = new ThrottlesWooCommerceSync();
    $nearlyExpired = ['createdAt' => now()->subHours(23)->getTimestamp(), 'retryUntil' => now()->addHour()->getTimestamp()];

    $healthy = ['createdAt' => now()->getTimestamp(), 'retryUntil' => now()->addDay()->getTimestamp()];

    $middleware->handle(throttleableJob(payload: $healthy), fn () => null);
    $middleware->handle(throttleableJob(payload: $nearlyExpired), fn () => null);

    $monitor->shouldNotHaveReceived('violated');
});

/**
 * The check reads createdAt and retryUntil from the queue payload. That is a
 * framework detail, so it is pinned here for both jobs, and a payload without
 * them is reported rather than silently left unchecked.
 */
it('finds the queue time and retry deadline in the payload of both jobs that push products to WooCommerce', function () {
    $queue = app('queue')->connection('redis');
    $createPayload = new ReflectionMethod($queue, 'createPayload');

    foreach ([
        new SerializedProcessProductsToWooCommerce(new WebkulProduct()),
        new ProcessProductsToWooCommerce(ProductBatch::fromProductArray(['sku' => 'SKU-1'])),
    ] as $job) {
        $payload = json_decode($createPayload->invoke($queue, $job, 'default'), true);

        expect($payload['createdAt'] ?? null)->toBe(now()->getTimestamp(), $job::class.' carries no createdAt.')
            ->and($payload['retryUntil'] ?? null)->toBe($job->retryUntil()->getTimestamp(), $job::class.' carries no retryUntil.');
    }
});

it('reports a sync whose deadline cannot be checked', function () {
    $monitor = $this->spy(WooCommerceAssumptionMonitor::class);

    (new ThrottlesWooCommerceSync())->handle(throttleableJob(payload: ['displayName' => 'SomeSync']), fn () => null);

    $monitor->shouldHaveReceived('violated')->with(
        WooCommerceAssumption::BacklogDrainsBeforeDeadline,
        'It cannot be checked for SomeSync: its queue payload carries no usable createdAt/retryUntil.',
        Mockery::any()
    )->once();
});
