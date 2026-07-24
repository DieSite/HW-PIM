<?php

namespace Webkul\WooCommerce\Listeners;

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
     * A re-sync is idempotent (the exporter upserts by SKU), so retry instead
     * of dying when a worker is killed mid-run (deploy restart, OOM) — the
     * expired reservation then re-runs the sync instead of surfacing as
     * MaxAttemptsExceededException, and transient WooCommerce 5xx errors get
     * a second chance too.
     */
    public $tries = 3;

    public $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private Product $product
    ) {
        $this->product->withoutRelations();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->product = Product::find($this->product->id);

        if (is_null($this->product->parent)) {
            $this->product->load('variants');
        } else {
            $this->product->load('parent');
        }
        ProcessProductsToWooCommerce::dispatchSync(ProductBatch::fromProductArray($this->product->toArray()));
    }

    public function failed(Throwable $exception): void
    {
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

        Sentry::captureException($exception);
    }
}
