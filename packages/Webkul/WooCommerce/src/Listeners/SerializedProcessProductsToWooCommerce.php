<?php

namespace Webkul\WooCommerce\Listeners;

use App\Enums\WooCommerceSyncEventStatus;
use App\Jobs\Middleware\DisconnectsIdleRedis;
use App\Jobs\Middleware\ThrottlesWooCommerceSync;
use App\Services\WooCommerce\WooCommerceSyncEventRecorder;
use App\Services\WooCommerce\WooCommerceSyncQueue;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Sentry\Laravel\Facade as Sentry;
use Throwable;
use Webkul\Product\Models\Product;
use Webkul\WooCommerce\DTO\ProductBatch;

class SerializedProcessProductsToWooCommerce implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;

    /**
     * Retries are bounded by retryUntil(), not by an attempt count.
     * ThrottlesWooCommerceSync releases this job back onto the queue whenever
     * the rate-limit window is full, and every release increments the attempt
     * counter without the job ever having run — an attempt cap would fail a
     * sync for nothing worse than waiting its turn behind a bulk export. The
     * deadline keeps the counter out of the decision entirely, and a killed
     * worker (deploy restart, OOM) stops burning lives with it.
     *
     * @var int
     */
    public $tries = 0;

    /**
     * Genuine failures stay bounded: this counter only advances when handle()
     * actually threw, so a throttled release does not consume a life. A
     * re-sync is idempotent (the exporter upserts by SKU), so transient
     * WooCommerce 5xx errors are worth two more goes.
     *
     * @var int
     */
    public $maxExceptions = 3;

    /**
     * The woocommerce_sync_queue entry this chain was claimed from. Not a
     * promoted property: a job queued before this property existed is
     * unserialized without running the constructor, and only a declared
     * default leaves it null instead of uninitialized.
     */
    private ?int $syncQueueEntryId = null;

    /**
     * Create a new job instance.
     *
     * @param  array<int, int>  $nextProductIds  Products to sync one after another once this one has
     *                                           succeeded. Replaces Bus::chain(), whose payload carries
     *                                           every remaining serialized job and so grows quadratically
     *                                           in Redis for a parent with many variants.
     * @param  int|null  $syncQueueEntryId  The woocommerce_sync_queue entry this chain was claimed from
     */
    public function __construct(
        private Product $product,
        private array $nextProductIds = [],
        ?int $syncQueueEntryId = null,
    ) {
        $this->product = $product->withoutRelations();
        $this->syncQueueEntryId = $syncQueueEntryId;
    }

    /**
     * Execute the job.
     */
    /**
     * May run past the Redis idle timeout without issuing a Redis command of
     * its own, so the delete() that retires it on success would find a closed
     * socket. {@see DisconnectsIdleRedis}
     *
     * One slot of the outgoing WooCommerce budget is spent per product here —
     * parent and variants alike, since each is its own job.
     * {@see ThrottlesWooCommerceSync}
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new DisconnectsIdleRedis(), new ThrottlesWooCommerceSync()];
    }

    /**
     * Long enough for the worst realistic backlog to drain at the configured
     * rate limit — a throttled job must eventually run, not expire in the
     * queue behind a bulk export.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours((int) config('woocommerce_sync.retry_deadline_hours'));
    }

    /**
     * Seconds to wait before each retry of a sync that threw. A retry that
     * comes back too soon lands on a shop still busy with the request we gave
     * up on. Throttled releases count as attempts too, so a job that waited
     * its turn first jumps straight to the longest delay.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return config('woocommerce_sync.backoff_seconds');
    }

    public function handle(WooCommerceSyncQueue $syncQueue): void
    {
        $syncQueue->started($this->syncQueueEntryId);

        $this->product = Product::find($this->product->id);

        if (is_null($this->product->parent)) {
            $this->product->load('variants');
        } else {
            $this->product->load('parent');
        }
        ProcessProductsToWooCommerce::dispatchSync(ProductBatch::fromProductArray($this->product->toArray()));

        if (! $this->dispatchNext()) {
            $syncQueue->finished($this->syncQueueEntryId);
        }
    }

    public function failed(Throwable $exception): void
    {
        app(WooCommerceSyncQueue::class)->finished($this->syncQueueEntryId);

        if ($exception instanceof ModelNotFoundException) {
            Log::info('Product no longer exists for WooCommerce sync', [
                'product_id' => $this->product->id,
            ]);

            return;
        }

        $product = Product::find($this->product->id);
        $syncError = $product?->additional['product_sync_error'] ?? 'unknown';

        Log::error('WooCommerce sync failed for product', [
            'product_id'         => $this->product->id,
            'sku'                => $this->product->sku,
            'product_sync_error' => $syncError,
            'exception'          => $exception->getMessage(),
        ]);

        // Without this the timeline is stranded on the "In wachtrij" or "Bezig"
        // event when the job dies for good (worker killed, tries exhausted).
        if ($product) {
            app(WooCommerceSyncEventRecorder::class)->record(
                $product,
                WooCommerceSyncEventStatus::Failed,
                'sync',
                $exception->getMessage(),
                'Synchronisatie definitief mislukt. Probeer het opnieuw.'
            );
        }

        Sentry::captureException($exception);
    }

    /**
     * Queue the next product of the chain, if any is left.
     */
    private function dispatchNext(): bool
    {
        $remainingIds = $this->nextProductIds;

        while (($nextId = array_shift($remainingIds)) !== null) {
            $next = Product::find($nextId);

            if ($next) {
                static::dispatch($next, $remainingIds, $this->syncQueueEntryId);

                return true;
            }
        }

        return false;
    }
}
