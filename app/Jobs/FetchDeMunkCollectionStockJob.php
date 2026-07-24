<?php

namespace App\Jobs;

use App\Clients\DeMunkPortalClient;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

/**
 * Fetch every in-stock De Munk article for a single collection (all qualities,
 * colours and sizes) and stash it as a cache "part"; ApplyDeMunkStockJob
 * merges the parts once the batch finishes.
 */
class FetchDeMunkCollectionStockJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const PART_CACHE_PREFIX = 'demunk_import_part_';

    /**
     * The portal is occasionally flaky; a retry refetches just this
     * collection.
     */
    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(public string $collection)
    {
        $this->onConnection('redis-demunk');
        $this->onQueue('demunk');
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $this->disconnectRedis();

        $client = (new DeMunkPortalClient())->login();

        $articles = [];
        $failedQualities = [];

        foreach ($client->qualities($this->collection) as $quality) {
            try {
                $found = $client->stockForQuality($this->collection, $quality);
            } catch (\Throwable $e) {
                $failedQualities[$quality] = $e->getMessage();

                continue;
            }

            foreach ($found as $article) {
                $articles[DeMunkPortalClient::articleKey($article)] = $article;
            }
        }

        if ($failedQualities !== [] && $this->attempts() < $this->tries) {
            throw new RuntimeException(sprintf(
                'De Munk collectie %s: kwaliteiten mislukt (%s)',
                $this->collection,
                implode(', ', array_keys($failedQualities)),
            ));
        }

        if ($failedQualities !== []) {
            Log::warning('De Munk collectie deels opgehaald; mislukte kwaliteiten overgeslagen', [
                'collection' => $this->collection,
                'failed'     => $failedQualities,
            ]);
        }

        Cache::put(self::partCacheKey($this->collection), array_values($articles), now()->addHours(12));
    }

    public static function partCacheKey(string $collection): string
    {
        return self::PART_CACHE_PREFIX.$collection;
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [self::class, $this->collection];
    }

    /**
     * The worker's Redis sockets sit idle during the minutes-long portal
     * crawl and have repeatedly gone stale (Predis "Error while reading/
     * writing bytes"), so open fresh ones for the cache write afterwards.
     */
    private function disconnectRedis(): void
    {
        Redis::connection('default')->disconnect();
        Redis::connection('cache')->disconnect();
    }
}
