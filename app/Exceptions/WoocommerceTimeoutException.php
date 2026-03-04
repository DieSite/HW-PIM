<?php

namespace App\Exceptions;

class WoocommerceTimeoutException extends \Exception
{
    public function __construct(string $sku, string $curlError)
    {
        parent::__construct("WooCommerce request timed out for product '$sku': $curlError");
    }
}