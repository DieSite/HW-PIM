<?php

use App\Jobs\ApplyDeMunkStockJob;
use App\Jobs\ApplyPhotoroomTransformationJob;
use App\Jobs\BulkEditProductsJob;
use App\Jobs\BulkSyncProductsWithBolComJob;
use App\Jobs\FetchDeMunkCollectionStockJob;
use App\Jobs\ImportProductsJob;
use App\Jobs\Middleware\DisconnectsIdleRedis;
use App\Jobs\ImportVoorraadDeMunkJob;
use App\Jobs\ImportVoorraadEurogrosJob;
use App\Jobs\MailHordeurenAnalysisReportJob;
use App\Jobs\NotifyMissingEurogrosEansJob;
use App\Jobs\RunHordeurenAnalysisJob;
use App\Jobs\ScrapeHordeurenCompetitorJob;
use App\Jobs\SyncProductWithBolComJob;
use App\Jobs\SyncWooCommerceStockJob;
use App\Models\BolComCredential;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Webkul\Product\Models\Product as WebkulProduct;
use Webkul\WooCommerce\DTO\ProductBatch;
use Webkul\WooCommerce\Listeners\DeleteProductFromWooCommerce;
use Webkul\WooCommerce\Listeners\ProcessProductsToWooCommerce;
use Webkul\WooCommerce\Listeners\SerializedProcessProductsToWooCommerce;

/**
 * Guardrails against the queue misconfiguration behind the recurring
 * MaxAttemptsExceededException incidents: a job (or its Horizon supervisor)
 * whose timeout reaches the connection's retry_after gets re-reserved
 * mid-flight, and the phantom extra attempt fails with "has been attempted
 * too many times" — retry_after seconds after dispatch, long after the real
 * cause is gone.
 *
 * Every queueable class must be constructible below with dummy arguments so
 * its constructor-set connection/queue routing is exercised for real. Add new
 * jobs here or the completeness check fails.
 *
 * @return array<class-string, callable(): object>
 */
function queueTimingJobFactories(): array
{
    return [
        ApplyDeMunkStockJob::class             => fn () => new ApplyDeMunkStockJob(['BASIC']),
        ApplyPhotoroomTransformationJob::class => fn () => new ApplyPhotoroomTransformationJob(1, 'afbeelding'),
        BulkEditProductsJob::class             => fn () => new BulkEditProductsJob([], [], false, 0),
        BulkSyncProductsWithBolComJob::class   => fn () => new BulkSyncProductsWithBolComJob(),
        FetchDeMunkCollectionStockJob::class   => fn () => new FetchDeMunkCollectionStockJob('BASIC'),
        ImportProductsJob::class               => fn () => new ImportProductsJob('products.xlsx'),
        ImportVoorraadDeMunkJob::class         => fn () => new ImportVoorraadDeMunkJob(),
        ImportVoorraadEurogrosJob::class       => fn () => new ImportVoorraadEurogrosJob(),
        MailHordeurenAnalysisReportJob::class  => fn () => new MailHordeurenAnalysisReportJob('test@example.com', now()),
        NotifyMissingEurogrosEansJob::class    => fn () => new NotifyMissingEurogrosEansJob(CarbonImmutable::now()),
        RunHordeurenAnalysisJob::class         => fn () => new RunHordeurenAnalysisJob('test@example.com'),
        ScrapeHordeurenCompetitorJob::class    => fn () => new ScrapeHordeurenCompetitorJob('01-voorbeeld.spec.js'),
        SyncProductWithBolComJob::class        => fn () => new SyncProductWithBolComJob(new WebkulProduct(), new BolComCredential()),
        SyncWooCommerceStockJob::class         => fn () => new SyncWooCommerceStockJob([]),

        DeleteProductFromWooCommerce::class           => fn () => new DeleteProductFromWooCommerce(['SKU-1']),
        ProcessProductsToWooCommerce::class           => fn () => new ProcessProductsToWooCommerce(ProductBatch::fromProductArray(['sku' => 'SKU-1'])),
        SerializedProcessProductsToWooCommerce::class => fn () => new SerializedProcessProductsToWooCommerce(new WebkulProduct()),
    ];
}

/**
 * @return array{name: string, config: array<string, mixed>}|null
 */
function queueTimingSupervisorForQueue(string $queue): ?array
{
    foreach (config('horizon.defaults') as $name => $supervisor) {
        if (in_array($queue, $supervisor['queue'], true)) {
            return ['name' => $name, 'config' => $supervisor];
        }
    }

    return null;
}

it('gives every horizon supervisor a connection whose retry_after exceeds the supervisor timeout', function () {
    foreach (config('horizon.defaults') as $name => $supervisor) {
        $retryAfter = (int) config("queue.connections.{$supervisor['connection']}.retry_after");

        expect($retryAfter)->toBeGreaterThan(
            (int) $supervisor['timeout'],
            "Supervisor [{$name}]: connection [{$supervisor['connection']}] retry_after ({$retryAfter}) must stay strictly above the supervisor timeout ({$supervisor['timeout']}), otherwise a running job is re-reserved mid-flight and fails with MaxAttemptsExceededException."
        );
    }
});

it('keeps every job timeout within its supervisor timeout and below its connection retry_after', function () {
    foreach (queueTimingJobFactories() as $class => $factory) {
        $job = $factory();

        // Jobs without explicit routing land on the production default
        // connection ("redis") and its default queue.
        $connection = $job->connection ?? 'redis';
        $queue = $job->queue ?? config("queue.connections.{$connection}.queue", 'default');
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

        $supervisor = queueTimingSupervisorForQueue($queue);

        expect($supervisor)->not->toBeNull(
            "[{$class}] runs on queue [{$queue}] which no Horizon supervisor serves — the job would never be picked up."
        );

        expect($supervisor['config']['connection'])->toBe(
            $connection,
            "[{$class}] routes to connection [{$connection}] but supervisor [{$supervisor['name']}] serves its queue via [{$supervisor['config']['connection']}]."
        );

        $timeout = (int) ($job->timeout ?? $supervisor['config']['timeout']);

        expect($timeout)->toBeLessThanOrEqual(
            (int) $supervisor['config']['timeout'],
            "[{$class}] timeout ({$timeout}) exceeds the timeout of supervisor [{$supervisor['name']}] serving queue [{$queue}]."
        );

        expect($timeout)->toBeLessThan(
            $retryAfter,
            "[{$class}] timeout ({$timeout}) must stay strictly below [{$connection}] retry_after ({$retryAfter})."
        );
    }
});

/**
 * Production Redis closes any client idle this long — verified 2026-07-29 on
 * the live instance: `CONFIG GET timeout` => 120, `tcp-keepalive` => 300 (so
 * the keepalive never fires first and cannot help).
 *
 * @var int
 */
const REDIS_IDLE_TIMEOUT = 120;

/**
 * Every queue job ends with a Redis command whether or not its body issues one:
 * RedisJob::delete() is how a finished job leaves the reserved set. So a body
 * that can run past REDIS_IDLE_TIMEOUT without talking to Redis will find the
 * socket closed when it succeeds — Predis throws "Error while writing bytes to
 * the server", the worker quits on the lost connection, the job stays reserved,
 * and retry_after later it returns as an attempt nothing explains.
 *
 * That is the confirmed cause of the hordeuren scrape failures, and it does not
 * depend on the job doing anything unusual — only on it taking more than two
 * minutes. Anything whose effective timeout admits that must drop its sockets
 * up front. The middleware is a no-op when it is not needed, so the cost of
 * applying it too widely is nil and the cost of forgetting it is a run that
 * fails for a reason its own failure record cannot show.
 *
 * NOTE: cache here is the file driver, so `Cache::` calls do NOT count as
 * Redis traffic. Only a job dispatch (a queue push) does.
 */
it('drops idle redis sockets on every job that can outlive the redis idle timeout', function () {
    foreach (queueTimingJobFactories() as $class => $factory) {
        $job = $factory();

        $connection = $job->connection ?? 'redis';
        $queue = $job->queue ?? config("queue.connections.{$connection}.queue", 'default');
        $supervisor = queueTimingSupervisorForQueue($queue);

        $timeout = (int) ($job->timeout ?? $supervisor['config']['timeout']);

        if ($timeout < REDIS_IDLE_TIMEOUT) {
            continue;
        }

        expect(method_exists($job, 'middleware'))->toBeTrue(
            "[{$class}] may run for {$timeout}s, past the ".REDIS_IDLE_TIMEOUT."s Redis idle timeout, so it must declare middleware() applying DisconnectsIdleRedis."
        );

        $middleware = array_map(fn ($m) => $m::class, $job->middleware());

        expect(in_array(DisconnectsIdleRedis::class, $middleware, true))->toBeTrue(
            "[{$class}] may run for {$timeout}s, past the ".REDIS_IDLE_TIMEOUT."s Redis idle timeout. Without DisconnectsIdleRedis the delete() that retires it on SUCCESS dies on a closed socket and the job comes back as an unexplained extra attempt."
        );
    }
});

it('keeps every WithoutOverlapping lock expiry at or below the connection retry_after', function () {
    foreach (queueTimingJobFactories() as $class => $factory) {
        $job = $factory();

        if (! method_exists($job, 'middleware')) {
            continue;
        }

        $connection = $job->connection ?? 'redis';
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

        foreach ($job->middleware() as $middleware) {
            if (! $middleware instanceof WithoutOverlapping || $middleware->expiresAfter === null) {
                continue;
            }

            expect((int) $middleware->expiresAfter)->toBeLessThanOrEqual(
                $retryAfter,
                "[{$class}] WithoutOverlapping expireAfter ({$middleware->expiresAfter}) outlives [{$connection}] retry_after ({$retryAfter}): with dontRelease() the silent-death retry would be swallowed while the stale lock is alive."
            );
        }
    }
});

/**
 * Connections whose jobs must bound their retries by a deadline instead of an
 * attempt count. Add a connection here once its jobs are converted.
 *
 * The timing invariants above only stop a *running* job from being re-reserved.
 * They cannot help when the worker itself disappears — a deploy restart, an
 * OOM kill, a replaced container. That reserves the job, bumps its attempt
 * counter and then vanishes without running failed(), releasing the job or
 * recording an exception, so the attempt is burnt invisibly. On a queue whose
 * jobs run for many minutes those deaths accumulate, and the attempt after the
 * last one dies instantly with "has been attempted too many times" — the job
 * that never even started.
 *
 * A job on such a connection has to pick one of two coherent policies, and the
 * failure this file exists for is picking neither:
 *
 * 1. Deadline-bounded. retryUntil() removes the attempt counter from the
 *    decision entirely (Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts
 *    returns early while the deadline is in the future), and maxExceptions
 *    keeps genuine failures bounded because that counter only advances when
 *    handle() actually threw. Retries survive killed workers.
 *
 * 2. Fail fast. $tries = 1 and no deadline, so the second reservation — the
 *    fingerprint of a killed worker — fails the job and alerts instead of
 *    quietly starting over. Chosen where a silent retry is worse than a loud
 *    stop, as for a competitor scrape whose half-finished run would still
 *    produce a report that reads as complete.
 *
 * A plain attempt cap ($tries > 1) is what neither policy allows: it cannot
 * tell a failure from a killed worker, so it burns attempts invisibly.
 *
 * @var list<string>
 */
const DEADLINE_BOUNDED_CONNECTIONS = ['redis-hordeuren'];

it('bounds long-running jobs by a deadline or by an explicit single attempt', function () {
    foreach (queueTimingJobFactories() as $class => $factory) {
        $job = $factory();

        if (! in_array($job->connection ?? 'redis', DEADLINE_BOUNDED_CONNECTIONS, true)) {
            continue;
        }

        /** Policy 2: fail fast. One attempt, and the retry that never comes is the point. */
        if (! method_exists($job, 'retryUntil')) {
            expect((int) ($job->tries ?? 0))->toBe(
                1,
                "[{$class}] has no retryUntil(), so it must fail fast with \$tries = 1. Any other cap on this queue burns attempts on killed workers and surfaces as MaxAttemptsExceededException on a job that never ran."
            );

            continue;
        }

        expect($job->retryUntil())->toBeInstanceOf(
            DateTimeInterface::class,
            "[{$class}] retryUntil() must return a DateTimeInterface."
        );

        expect($job->retryUntil()->getTimestamp())->toBeGreaterThan(
            now()->getTimestamp(),
            "[{$class}] retryUntil() must be in the future, or every attempt fails immediately."
        );

        expect((int) ($job->tries ?? 0))->toBe(
            0,
            "[{$class}] still sets \$tries. With retryUntil() the worker ignores it, so leaving it set only misleads the next reader."
        );

        expect($job->maxExceptions ?? null)->toBeGreaterThan(
            0,
            "[{$class}] must cap genuine failures with \$maxExceptions: with retryUntil() and no cap, a permanently broken job retries until the deadline and blocks the single-process queue behind it."
        );
    }
});

/**
 * This used to assert the opposite: that the scrape's retryUntil() deadline
 * covered a whole sequential batch. That deadline is gone on purpose. It made
 * the attempt count unreadable, so a competitor that kept losing its worker
 * kept being handed to another one — up to attempt 24 over 24.7 hours — while
 * the run looked alive and nothing was ever mailed. One attempt, then an alert.
 */
it('gives the hordeuren scrape exactly one attempt and no retry deadline', function () {
    $job = new ScrapeHordeurenCompetitorJob('01-voorbeeld.spec.js');

    expect($job->tries)->toBe(1)
        ->and(method_exists($job, 'retryUntil'))->toBeFalse();

    /** The orchestrator still needs its deadline: its own npm/Chromium install is the long part. */
    expect((new RunHordeurenAnalysisJob('rapport@voorbeeld.nl'))->retryUntil()->getTimestamp())
        ->toBeGreaterThan(now()->getTimestamp());
});

it('covers every queueable class with a factory so new jobs cannot dodge the invariants', function () {
    $scanned = collect(glob(app_path('Jobs/*.php')))
        ->map(fn (string $file) => 'App\\Jobs\\'.basename($file, '.php'))
        ->merge(
            collect(glob(base_path('packages/Webkul/WooCommerce/src/Listeners/*.php')))
                ->map(fn (string $file) => 'Webkul\\WooCommerce\\Listeners\\'.basename($file, '.php'))
        )
        ->filter(fn (string $class) => class_exists($class) && is_subclass_of($class, ShouldQueue::class));

    $missing = $scanned->diff(array_keys(queueTimingJobFactories()));

    expect($missing->values()->all())->toBe(
        [],
        'Add these queueable classes to queueTimingJobFactories() in '.__FILE__.' so their queue timing is guarded: '.$missing->implode(', ')
    );
});
