<?php

namespace App\Exceptions;

/**
 * WooCommerce refused to create a product because its SKU is still claimed in
 * wc_product_meta_lookup. The SKU lock on create ignores post status, so a
 * trashed product (invisible to the regular SKU search) blocks re-creation.
 */
class WoocommerceProductSkuInLookupTableException extends \Exception
{
    public function __construct(public readonly string $sku)
    {
        parent::__construct("SKU {$sku} bestaat al in de WooCommerce zoektabel.");
    }
}
