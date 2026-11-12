<?php

namespace App\Services\WooCommerce;

use App\Enums\WooCommerceSyncEventStatus;
use App\Models\WooCommerceSyncEvent;
use App\Models\WooCommerceSyncQueueEntry;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Webkul\Product\Models\Product;
use Webkul\WooCommerce\Listeners\SerializedProcessProductsToWooCommerce;

/**
 * The waiting room in front of the WooCommerce sync.
 *
 * Products wait in the woocommerce_sync_queue table instead of as queued jobs
 * in Redis. A bulk resync used to put thousands of jobs on the Redis queue;
 * at ~4 KB each (the job plus Horizon's copy) they filled Redis to its
 * memory cap and stopped every queue, while the throttle released each of
 * them every few seconds without running it.
 *
 * Now only max_in_flight syncs are on the Redis queue at once. pump() tops
 * them up from the table whenever one finishes, and a scheduled pump
 * reclaims entries whose job was lost. A product queued again while it is
 * still waiting is merged into its existing entry.
 */
class WooCommerceSyncQueue
{
    private const PUMP_LOCK = 'woocommerce-sync-queue-pump';

    /**
     * Queue a sync of the product, followed by the given products one after
     * another once it has succeeded (a parent then its variants).
     *
     * @param  array<int, int>  $nextProductIds
     */
    public function enqueue(Product $product, array $nextProductIds = []): void
    {
        $nextProductIds = array_values(array_map('intval', $nextProductIds));

        try {
            $entry = WooCommerceSyncQueueEntry::query()->createOrFirst(
                ['product_id' => $product->id],
                ['next_product_ids' => $nextProductIds],
            );
        } catch (UniqueConstraintViolationException) {
            $entry = WooCommerceSyncQueueEntry::query()->where('product_id', $product->id)->firstOrFail();
        }

        if (! $entry->wasRecentlyCreated) {
            $entry->next_product_ids = array_values(array_unique([...($entry->next_product_ids ?? []), ...$nextProductIds]));

            /*
             * A sync already under way may have read the product before this
             * change, so it runs once more after it finishes.
             */
            if ($entry->claimed_at !== null) {
                $entry->rerun_requested = true;
            }

            $entry->save();
        }

        $this->pump();
    }

    /**
     * Put waiting entries on the Redis queue until max_in_flight are there.
     */
    public function pump(): void
    {
        try {
            $claimed = Cache::lock(self::PUMP_LOCK, 30)->block(5, fn (): Collection => $this->claim());
        } catch (LockTimeoutException) {
            return;
        }

        foreach ($claimed as $entry) {
            SerializedProcessProductsToWooCommerce::dispatch($entry->product, $entry->next_product_ids ?? [], $entry->id);
        }
    }

    /**
     * The entry's sync started a product: it is alive, not lost.
     */
    public function started(?int $entryId): void
    {
        if ($entryId === null) {
            return;
        }

        WooCommerceSyncQueueEntry::query()->whereKey($entryId)->claimed()->update(['claimed_at' => now()]);
    }

    /**
     * The entry's sync (and the chain it carried) is done, successfully or
     * for good: free its slot for the next waiting product.
     */
    public function finished(?int $entryId): void
    {
        if ($entryId === null) {
            return;
        }

        $entry = WooCommerceSyncQueueEntry::query()->find($entryId);

        if ($entry?->rerun_requested) {
            $entry->update(['claimed_at' => null, 'rerun_requested' => false]);
        } else {
            $entry?->delete();
        }

        $this->pump();
    }

    public function waitingCount(): int
    {
        return WooCommerceSyncQueueEntry::query()->waiting()->count();
    }

    /**
     * Waiting entries in queue order, for Horizon's pending list.
     *
     * @return Collection<int, WooCommerceSyncQueueEntry>
     */
    public function waiting(int $offset, int $limit): Collection
    {
        return WooCommerceSyncQueueEntry::query()
            ->waiting()
            ->with('product:id,sku')
            ->orderBy('id')
            ->offset($offset)
            ->limit($limit)
            ->get();
    }

    /**
     * Seconds until the last waiting product gets its turn, at the pace
     * products actually synced over the past hour (or the rate limit, when
     * nothing synced in that hour).
     */
    public function estimatedWaitSeconds(): int
    {
        $waiting = $this->waitingCount();

        if ($waiting === 0) {
            return 0;
        }

        $syncedPastHour = WooCommerceSyncEvent::query()
            ->where('status', WooCommerceSyncEventStatus::Success)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        $perHour = $syncedPastHour > 0 ? $syncedPastHour : 60 * max(1, (int) config('woocommerce_sync.rate_limit.per_minute'));

        return (int) ceil($waiting / $perHour * 3600);
    }

    /**
     * @return Collection<int, WooCommerceSyncQueueEntry>
     */
    private function claim(): Collection
    {
        $this->reclaimStale();

        $free = (int) config('woocommerce_sync.queue.max_in_flight') - WooCommerceSyncQueueEntry::query()->claimed()->count();

        if ($free <= 0) {
            return collect();
        }

        $entries = WooCommerceSyncQueueEntry::query()
            ->waiting()
            ->with('product')
            ->orderBy('id')
            ->limit($free)
            ->get();

        WooCommerceSyncQueueEntry::query()->whereKey($entries->modelKeys())->update(['claimed_at' => now()]);

        return $entries;
    }

    private function reclaimStale(): void
    {
        $reclaimed = WooCommerceSyncQueueEntry::query()
            ->claimed()
            ->where('claimed_at', '<', now()->subMinutes((int) config('woocommerce_sync.queue.stale_after_minutes')))
            ->update(['claimed_at' => null]);

        if ($reclaimed > 0) {
            Log::warning('WooCommerce sync queue: requeued syncs whose job was lost', ['entries' => $reclaimed]);
        }
    }
}
