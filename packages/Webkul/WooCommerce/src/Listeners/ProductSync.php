<?php

namespace Webkul\WooCommerce\Listeners;

use App\Services\WooCommerce\WooCommerceSyncEventRecorder;
use App\Services\WooCommerce\WooCommerceSyncQueue;
use Webkul\Product\Models\Product;
use Webkul\Product\Repositories\ProductRepository;

class ProductSync
{
    public function __construct(
        protected ProductRepository $productRepository,
        protected WooCommerceSyncEventRecorder $syncEventRecorder,
        protected WooCommerceSyncQueue $wooCommerceSyncQueue,
    ) {}

    /**
     * Queues the product by model identifier only. Serialising the full
     * toArray() of a parent with its variants put ~130 KB into every Redis
     * payload (kept by Horizon as pending/completed/failed job), which ran
     * Redis out of memory during bulk saves. The job re-fetches the product
     * with its relations when it runs. It waits its turn in the
     * woocommerce_sync_queue table, not in Redis. {@see WooCommerceSyncQueue}
     */
    public function syncProductToWooCommerce(Product $product): void
    {
        $this->syncEventRecorder->queued($product);

        $this->wooCommerceSyncQueue->enqueue($product);
    }

    public function deleteProductFromWooCommerce($productId)
    {
        $sku = $this->productRepository->pluck('sku', 'id')->get($productId);

        DeleteProductFromWooCommerce::dispatch($sku);
    }
}
