<?php

namespace Webkul\Admin\Listeners;

use App\Services\ProductService;
use Webkul\Product\Models\Product;

class ProductStoredListener
{
    public function fillMetaValues(Product $product)
    {
        $values = $product->values;

        $productService = app(ProductService::class);

        if (empty($values['common']['meta_title']) && ! empty($values['common']['productnaam'] && ! empty($values['common']['merk']))) {
            $values['common']['meta_title'] = $productService->generateMetaTitle($values['common']['productnaam'], $values['common']['merk']);
        }

        if (empty($values['common']['meta_description']) && ! empty($values['common']['productnaam'])) {
            $values['common']['meta_description'] = $productService->generateMetaDescription($values['common']['productnaam']);
        }

        $product->values = $values;
        $product->save();
    }
}
