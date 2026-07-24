<?php

use App\Jobs\ApplyDeMunkStockJob;
use App\Jobs\ApplyPhotoroomTransformationJob;
use App\Jobs\BulkEditProductsJob;
use App\Jobs\BulkSyncProductsWithBolComJob;
use App\Jobs\FetchDeMunkCollectionStockJob;
use App\Jobs\ImportProductsJob;
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
