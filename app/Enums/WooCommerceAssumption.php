<?php

namespace App\Enums;

use App\Services\WooCommerce\WooCommerceAssumptionMonitor;

/**
 * What the WooCommerce sync takes for granted about the shop. WooCommerce,
 * WordPress and their plugins change underneath us, so none of these is
 * checked once and trusted: every response is checked against them, and a
 * failed check is reported. {@see WooCommerceAssumptionMonitor}
 */
enum WooCommerceAssumption: string
{
    case EveryImageListed = 'every-image-listed';
    case ImagesInSentOrder = 'images-in-sent-order';
    case SkuSavedAsSent = 'sku-saved-as-sent';
    case ImageRejectionNamesId = 'image-rejection-names-id';
    case BacklogDrainsBeforeDeadline = 'backlog-drains-before-deadline';

    public function description(): string
    {
        return match ($this) {
            self::EveryImageListed            => 'WooCommerce lists every image it was sent in its answer.',
            self::ImagesInSentOrder           => 'WooCommerce lists the images in the order they were sent.',
            self::SkuSavedAsSent              => 'WooCommerce saves a product under the SKU it was sent.',
            self::ImageRejectionNamesId       => 'WooCommerce names the attachment id ("#123") when it rejects an image.',
            self::BacklogDrainsBeforeDeadline => 'A queued sync gets its turn well before its retry deadline.',
        };
    }

    /**
     * What the sync does about it, so whoever reads the alert knows the
     * impact without opening the code.
     */
    public function consequence(): string
    {
        return match ($this) {
            self::EveryImageListed, self::ImagesInSentOrder => 'Images are not matched to attachments for this product, so it uploads its images again on every sync.',
            self::SkuSavedAsSent                            => 'The PIM and the shop disagree on the SKU, so the next sync may not find this product.',
            self::ImageRejectionNamesId                     => 'A deleted attachment is not recovered from automatically, so this product keeps failing until its image mappings are removed.',
            self::BacklogDrainsBeforeDeadline               => 'Syncs that do not get their turn before the deadline fail without ever reaching the shop.',
        };
    }
}
