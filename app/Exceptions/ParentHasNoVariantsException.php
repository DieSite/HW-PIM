<?php

namespace App\Exceptions;

class ParentHasNoVariantsException extends \Exception
{
    public function __construct(string $sku)
    {
        parent::__construct("Product '$sku' has no variants. Add at least one variant before syncing to WooCommerce.");
    }
}