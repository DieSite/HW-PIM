<?php

namespace App\Jobs;

use App\Clients\DeMunkPortalClient;
use App\Services\DeMunkMatcher;
use App\Services\DeMunkStockWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Sentry;
use Throwable;

/**
 * Close out a De Munk voorraad import batch: merge the per-collection cache
 * parts, (re)build the auto product links and write the stock onto the linked
 * PIM size variants. Dispatched by the batch's finally callback, so it runs
 * whether or not individual collections failed — available collections are
 * applied, missing ones are reported loudly instead of silently zeroing
 * stock.
 */
class ApplyDeMunkStockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A re-apply is idempotent (links and stock are overwritten), so a killed
     * worker's expired reservation simply re-runs it.
     */
    public int $tries = 2;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    /**
     * @param  list<string>  $collections
     */
    public function __construct(public array $collections)
    {
        $this->onConnection('redis-demunk');
        $this->onQueue('demunk');
    }

    public function handle(DeMunkMatcher $matcher, DeMunkStockWriter $writer): void
    {
        try {
            [$articles, $missingCollections] = $this->mergeParts();

            if ($articles === []) {
                throw new RuntimeException(
                    'De Munk import: geen enkele collectie opgehaald ('
                    .implode(', ', $missingCollections).'); voorraad niet aangepast.'
                );
            }

            if ($missingCollections !== []) {
                Log::warning('De Munk import: collecties ontbreken; hun voorraad blijft ongewijzigd tot de volgende run', [
                    'missing' => $missingCollections,
                ]);
                Sentry::captureMessage(
                    'De Munk import: collecties ontbreken: '.implode(', ', $missingCollections),
                );
            }

            $fetchedCollections = array_values(array_diff($this->collections, $missingCollections));

            $matchResult = $matcher->sync($articles);
            $writeResult = $writer->apply($articles, $fetchedCollections);

            $this->storeSnapshot($articles, $matchResult);
            $this->cleanupParts();

            Log::info('De Munk voorraad import voltooid', [
                'articles'            => count($articles),
                'missing_collections' => $missingCollections,
                'linked'              => $matchResult['linked'],
                'skipped_locked'      => $matchResult['skipped_locked'],
                'unmatched'           => count($matchResult['unmatched']),
                'products_touched'    => $writeResult['products'],
                'variants_changed'    => $writeResult['variants_changed'],
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
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function mergeParts(): array
    {
        $articles = [];
        $missing = [];

        foreach ($this->collections as $collection) {
            $part = Cache::get(FetchDeMunkCollectionStockJob::partCacheKey($collection));

            if (! is_array($part)) {
                $missing[] = $collection;

                continue;
            }

            foreach ($part as $article) {
                $articles[DeMunkPortalClient::articleKey($article)] = $article;
            }
        }

        return [array_values($articles), $missing];
    }

    private function cleanupParts(): void
    {
        foreach ($this->collections as $collection) {
            Cache::forget(FetchDeMunkCollectionStockJob::partCacheKey($collection));
        }

        Cache::forget(ImportVoorraadDeMunkJob::BATCH_CACHE_KEY);
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

        Cache::put(ImportVoorraadDeMunkJob::SNAPSHOT_CACHE_KEY, [
            'imported_at'   => now()->toIso8601String(),
            'article_count' => count($articles),
            'linked'        => $matchResult['linked'],
            'unmatched'     => array_values($unmatched),
        ], now()->addDays(14));
    }
}
