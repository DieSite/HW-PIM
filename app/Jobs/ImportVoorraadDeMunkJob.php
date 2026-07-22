<?php

namespace App\Jobs;

use App\Clients\DeMunkPortalClient;
use App\Services\DeMunkMatcher;
use App\Services\DeMunkStockWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Sentry;
use Throwable;

/**
 * Reads all De Munk in-stock articles from the dealer portal, (re)builds the
 * auto product links and writes the stock onto the linked PIM size variants.
 */
class ImportVoorraadDeMunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Cache key holding the last import snapshot for the admin review screen.
     */
    public const SNAPSHOT_CACHE_KEY = 'demunk_last_import_snapshot';

    public int $tries = 1;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public int $maxExceptions = 1;

    /**
     * Run on a dedicated connection/queue (Horizon supervisor-demunk) whose
     * retry_after exceeds this job's worst-case wall time. On the shared
     * queue the 11-minute retry_after re-reserved the still-running crawl,
     * and with $tries = 1 that second attempt failed instantly with "has
     * been attempted too many times".
     */
    public function __construct()
    {
        $this->onConnection('redis-demunk');
        $this->onQueue('demunk');
    }

    /**
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('import-demunk-voorraad'))->expireAfter($this->timeout)->dontRelease()];
    }

    public function handle(DeMunkMatcher $matcher, DeMunkStockWriter $writer): void
    {
        try {
            $client = (new DeMunkPortalClient())->login();

            $articles = $client->allStock();

            $matchResult = $matcher->sync($articles);
            $writeResult = $writer->apply($articles);

            $this->storeSnapshot($articles, $matchResult);

            Log::info('De Munk voorraad import voltooid', [
                'articles'         => count($articles),
                'linked'           => $matchResult['linked'],
                'skipped_locked'   => $matchResult['skipped_locked'],
                'unmatched'        => count($matchResult['unmatched']),
                'products_touched' => $writeResult['products'],
                'variants_changed' => $writeResult['variants_changed'],
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

    /**
     * Persist the in-stock articles that matched no PIM product so the admin
     * review screen can surface them.
     *
     * @param  list<array<string, mixed>>  $articles
     * @param  array{linked:int, skipped_locked:int, unmatched:list<array<string,string>>}  $matchResult
     */
    private function storeSnapshot(array $articles, array $matchResult): void
    {
        $unmatched = [];

        foreach ($matchResult['unmatched'] as $identity) {
            $key = $identity['collectie'].'|'.$identity['kwaliteit'].'|'.$identity['kleur'];
            $unmatched[$key] = $identity;
        }

        Cache::put(self::SNAPSHOT_CACHE_KEY, [
            'imported_at'   => now()->toIso8601String(),
            'article_count' => count($articles),
            'linked'        => $matchResult['linked'],
            'unmatched'     => array_values($unmatched),
        ], now()->addDays(14));
    }
}
