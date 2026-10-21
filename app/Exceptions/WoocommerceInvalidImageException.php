<?php

namespace App\Exceptions;

/**
 * WooCommerce refused a product because an image we sent by attachment id no
 * longer exists, typically because it was deleted from the WordPress media
 * library after we remembered it.
 */
class WoocommerceInvalidImageException extends \Exception
{
    /**
     * @param  array<int, string>  $attachmentIds
     */
    public function __construct(public readonly string $sku, public readonly array $attachmentIds)
    {
        parent::__construct('WooCommerce kent afbeelding #'.implode(', #', $attachmentIds)." van {$sku} niet meer.");
    }
}
