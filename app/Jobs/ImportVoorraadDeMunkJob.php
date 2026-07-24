<?php

namespace App\Jobs;

use App\Clients\DeMunkPortalClient;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Sentry;
use Throwable;

/**
 * Kick off the De Munk voorraad import: list the portal's collections and
 * dispatch one FetchDeMunkCollectionStockJob per collection as a batch;
 * ApplyDeMunkStockJob then merges the partial results, (re)builds the auto
 * product links and writes the stock onto the linked PIM size variants.
 *
 * Splitting the former half-hour crawl means a killed worker (deploy restart,
 * OOM) loses one collection's fetch instead of the whole import, and each
 * collection retries independently.
 */
class ImportVoorraadDeMunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Cache key holding the last import snapshot for the admin review screen.
     */
    public const SNAPSHOT_CACHE_KEY = 'demunk_last_import_snapshot';

    /**
     * Cache key holding the current fetch batch id, guarding against a new
     * import starting while the previous batch is still running.
     */
    public const BATCH_CACHE_KEY = 'demunk_import_batch_id';

    /**
     * With failOnTimeout and maxExceptions = 1, the second attempt is reached
     * exclusively when the first attempt died silently (worker killed on
     * deploy/restart, OOM): a genuine timeout or exception still fails
     * immediately.
     */
    public int $tries = 2;

    /**
     * Only login + listing the collections; the crawl itself happens in the
     * per-collection batch jobs.
     */
    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public int $maxExceptions = 1;

    /**
     * Run on a dedicated connection/queue (Horizon supervisor-demunk) whose
     * retry_after exceeds every demunk job's timeout. On the shared queue the
     * short retry_after re-reserved still-running jobs, which surfaced as
     * "has been attempted too many times".
     */
    public function __construct()
    {
        $this->onConnection('redis-demunk');
        $this->onQueue('demunk');
    }

    /**
     * expireAfter must stay at or below the connection's retry_after: the
     * silent-death retry arrives exactly retry_after seconds after dispatch,
     * and an unexpired lock combined with dontRelease() would swallow that
     * retry without a trace.
     *
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('import-demunk-voorraad'))->expireAfter($this->timeout)->dontRelease()];
    }

    public function handle(): void
    {
        if ($this->previousBatchStillRunning()) {
            Log::warning('De Munk voorraad import overgeslagen: de vorige import draait nog');

            return;
        }

        try {
            $client = (new DeMunkPortalClient())->login();

            $collections = $client->collections();

            if ($collections === []) {
                throw new RuntimeException('De Munk portal returned no collections.');
            }

            $batch = Bus::batch(array_map(
                fn (string $collection) => new FetchDeMunkCollectionStockJob($collection),
                $collections,
            ))
                ->allowFailures()
                ->name('demunk-voorraad-import')
                ->finally(function (Batch $batch) use ($collections): void {
                    ApplyDeMunkStockJob::dispatch($collections);
                })
                ->onConnection('redis-demunk')
                ->onQueue('demunk')
                ->dispatch();

            Cache::put(self::BATCH_CACHE_KEY, $batch->id, now()->addHours(12));

            Log::info('De Munk voorraad import gestart', [
                'collections' => count($collections),
                'batch_id'    => $batch->id,
            ]);
        } catch (Throwable $e) {
            Sentry::withScope(function ($scope) use ($e): void {
                $scope->setContext('demunk_import', ['message' => $e->getMessage()]);
                Sentry::captureException($e);
            });

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Sentry::captureException($exception);

        Log::error('De Munk voorraad import mislukt', [
            'message' => $exception->getMessage(),
            'file'    => $exception->getFile(),
            'line'    => $exception->getLine(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [self::class];
    }

    private function previousBatchStillRunning(): bool
    {
        $batchId = Cache::get(self::BATCH_CACHE_KEY);

        if (! is_string($batchId) || $batchId === '') {
            return false;
        }

        $batch = Bus::findBatch($batchId);

        return $batch !== null && ! $batch->finished() && ! $batch->cancelled();
    }
}
