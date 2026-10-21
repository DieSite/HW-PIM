<?php

namespace Webkul\Admin\Listeners;

use App\Services\ProductService;
use Webkul\Product\Models\Product;

class ProductStoredListener
{
    /**
     * Gives a new product an SEO title when none was entered. Only meta_titel
     * and meta_beschrijving reach the shop (as _yoast_wpseo_title/_metadesc,
     * which the diesite-pim-seo-meta plugin copies into AIOSEO). The meta
     * description is left alone: the old template text is no longer wanted,
     * and the AI texts or the editor fill it.
     */
    public function fillMetaValues(Product $product)
    {
        $values = $product->values;

        if (! empty($values['common']['meta_titel']) || empty($values['common']['productnaam']) || empty($values['common']['merk'])) {
            return;
        }

        $values['common']['meta_titel'] = app(ProductService::class)->generateMetaTitle($values['common']['productnaam'], $values['common']['merk']);

        $product->values = $values;
        $product->save();
    }
}
