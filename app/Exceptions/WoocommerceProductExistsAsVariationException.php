<?php

namespace App\Exceptions;

class WoocommerceProductExistsAsVariationException extends \Exception
{
    public function __construct(public readonly string $sku)
    {
        parent::__construct("Product with SKU $sku already exists as a variation in WooCommerce.");
    }
}