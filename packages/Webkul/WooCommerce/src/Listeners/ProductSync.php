<?php

namespace Webkul\WooCommerce\Listeners;

use Webkul\Product\Models\Product;
use Webkul\Product\Repositories\ProductRepository;

class ProductSync
{
    public function __construct(protected ProductRepository $productRepository) {}

    public function syncProductToWooCommerce(Product $product)
    {
        $product->load(['parent', 'variants']);
        ProcessProductsToWooCommerce::dispatch($product->toArray());
    }

    public function deleteProductFromWooCommerce($productId)
    {
        $sku = $this->productRepository->pluck('sku', 'id')->get($productId);

        DeleteProductFromWooCommerce::dispatch($sku);
    }
}
