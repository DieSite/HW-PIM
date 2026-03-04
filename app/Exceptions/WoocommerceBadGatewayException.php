<?php

namespace App\Exceptions;

class WoocommerceBadGatewayException extends \Exception
{
    public function __construct(string $sku)
    {
        parent::__construct("WooCommerce returned a 502 Bad Gateway for product '$sku'. The request will be retried.");
    }
}