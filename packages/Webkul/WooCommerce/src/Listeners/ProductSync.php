<?php

namespace Webkul\WooCommerce\Listeners;

use Webkul\Product\Models\Product;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\WooCommerce\DTO\ProductBatch;

class ProductSync
{
    public function __construct(protected ProductRepository $productRepository) {}

    public function syncProductToWooCommerce(Product $product)
    {
        $product->load(['parent', 'variants']);
        ProcessProductsToWooCommerce::dispatch(ProductBatch::fromProductArray($product->toArray()));
    }

    public function deleteProductFromWooCommerce($productId)
    {
        $sku = $this->productRepository->pluck('sku', 'id')->get($productId);

        DeleteProductFromWooCommerce::dispatch($sku);
    }
}
