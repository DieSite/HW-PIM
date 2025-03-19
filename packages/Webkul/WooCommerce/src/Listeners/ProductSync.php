<?php

namespace Webkul\WooCommerce\Listeners;

use Webkul\Product\Repositories\ProductRepository;

class ProductSync
{
    public function __construct(protected ProductRepository $productRepository) {}

    public function syncProductToWooCommerce($product)
    {
        ProcessProductsToWooCommerce::dispatch($product->toArray());
    }

    public function deleteProductFromWooCommerce($productId)
    {
        $sku = $this->productRepository->pluck('sku', 'id')->get($productId);

        DeleteProductFromWooCommerce::dispatch($sku);
    }
}
