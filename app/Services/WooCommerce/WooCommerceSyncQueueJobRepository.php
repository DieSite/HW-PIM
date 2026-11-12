<?php

namespace App\Services\WooCommerce;

use App\Models\WooCommerceSyncQueueEntry;
use Laravel\Horizon\Repositories\RedisJobRepository;
use Webkul\WooCommerce\Listeners\SerializedProcessProductsToWooCommerce;

/**
 * Horizon's job repository, with the products waiting in the WooCommerce
 * sync waiting room listed as pending jobs behind the real ones.
 *
 * They never were Redis jobs, so Horizon would not show them otherwise.
 * They appear on the queue QUEUE, have ids prefixed with ID_PREFIX and open
 * in Horizon's job detail view like any other job.
 */
class WooCommerceSyncQueueJobRepository extends RedisJobRepository
{
    public const QUEUE = 'woocommerce-wachtrij';

    public const ID_PREFIX = 'woocommerce-wachtrij-';

    /**
     * Horizon's pending page size.
     */
    private const PAGE_SIZE = 50;

    public function countPending()
    {
        return parent::countPending() + $this->syncQueue()->waitingCount();
    }

    /**
     * Horizon pages through pending jobs by index: the Redis jobs come first,
     * then the waiting room fills up the rest of the page.
     */
    public function getPending($afterIndex = null)
    {
        $start = ($afterIndex ?? -1) + 1;
        $redisCount = parent::countPending();

        $jobs = $start < $redisCount ? parent::getPending($afterIndex) : collect();
        $missing = self::PAGE_SIZE - $jobs->count();

        if ($missing <= 0) {
            return $jobs;
        }

        $offset = max(0, $start - $redisCount);
        $waiting = $this->syncQueue()
            ->waiting($offset, $missing)
            ->values()
            ->map(fn (WooCommerceSyncQueueEntry $entry, int $i): object => $this->asHorizonJob($entry, $start + $jobs->count() + $i));

        return $jobs->concat($waiting)->values();
    }

    public function getJobs(array $ids, $indexFrom = 0)
    {
        [$waitingIds, $redisIds] = collect($ids)->partition(fn (string $id): bool => str_starts_with($id, self::ID_PREFIX));

        $jobs = $redisIds->isEmpty() ? collect() : parent::getJobs($redisIds->values()->all(), $indexFrom);

        if ($waitingIds->isEmpty()) {
            return $jobs;
        }

        $entries = WooCommerceSyncQueueEntry::query()
            ->with('product:id,sku')
            ->whereKey($waitingIds->map(fn (string $id): int => (int) substr($id, strlen(self::ID_PREFIX)))->all())
            ->get();

        return $jobs->concat($entries->map(fn (WooCommerceSyncQueueEntry $entry): object => $this->asHorizonJob($entry, $indexFrom)))->values();
    }

    /**
     * The entry in the shape of a job record from Horizon's Redis store.
     */
    private function asHorizonJob(WooCommerceSyncQueueEntry $entry, int $index): object
    {
        $nextProductIds = $entry->next_product_ids ?? [];

        return (object) [
            'id'           => self::ID_PREFIX.$entry->id,
            'connection'   => 'database',
            'queue'        => self::QUEUE,
            'name'         => SerializedProcessProductsToWooCommerce::class,
            'status'       => $entry->claimed_at ? 'reserved' : 'pending',
            'payload'      => json_encode([
                'uuid'        => self::ID_PREFIX.$entry->id,
                'id'          => self::ID_PREFIX.$entry->id,
                'displayName' => SerializedProcessProductsToWooCommerce::class,
                'pushedAt'    => (string) $entry->created_at->getTimestamp(),
                'attempts'    => 0,
                'tags'        => array_values(array_filter([
                    $entry->product?->sku,
                    'product:'.$entry->product_id,
                    $nextProductIds ? '+'.count($nextProductIds).' varianten' : null,
                ])),
                'data' => [
                    'commandName' => SerializedProcessProductsToWooCommerce::class,
                    'command'     => serialize([
                        'product_id'       => $entry->product_id,
                        'sku'              => $entry->product?->sku,
                        'next_product_ids' => $nextProductIds,
                        'rerun_requested'  => $entry->rerun_requested,
                        'waiting_since'    => $entry->created_at->toIso8601String(),
                    ]),
                ],
            ]),
            'exception'    => null,
            'context'      => null,
            'failed_at'    => null,
            'completed_at' => null,
            'retried_by'   => null,
            'reserved_at'  => null,
            'delay'        => null,
            'index'        => $index,
        ];
    }

    private function syncQueue(): WooCommerceSyncQueue
    {
        return app(WooCommerceSyncQueue::class);
    }
}
