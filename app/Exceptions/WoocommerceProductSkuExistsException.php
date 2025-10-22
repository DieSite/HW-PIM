<?php

namespace App\Exceptions;

class WoocommerceProductSkuExistsException extends \Exception
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $sku
    ) {
        parent::__construct("Product with external ID $externalId and SKU $sku already exists.");
    }
}
