<?php

namespace App\Services\Bol;

use App\Clients\BolApiClient;
use Webkul\Product\Models\Product;

/**
 * Applies updates (price / stock / details) to an existing Bol.com offer.
 *
 * The three PUTs each return their own processStatusId. We don't currently poll
 * those — best-effort updates. If we ever need stronger guarantees, return the
 * status ids and have the state machine poll them.
 */
class BolOfferUpdater
{
    public function __construct(private readonly BolPayloadBuilder $builder) {}

    public function update(BolApiClient $apiClient, Product $product, string $reference, string $deliveryCode): array
    {
        return [
            'price'   => $apiClient->put('/retailer/offers/'.$reference.'/price', $this->builder->updatePrice($product)),
            'stock'   => $apiClient->put('/retailer/offers/'.$reference.'/stock', $this->builder->updateStock($product)),
            'details' => $apiClient->put('/retailer/offers/'.$reference, $this->builder->updateOffer($product, $deliveryCode)),
        ];
    }
}
