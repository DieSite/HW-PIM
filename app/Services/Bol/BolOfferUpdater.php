<?php

namespace App\Services\Bol;

use App\Clients\BolApiClient;
use Webkul\Product\Models\Product;

/**
 * Applies updates to an existing Bol.com offer via the Offers API v11.
 *
 * v11 collapsed the three separate v10 PUTs (price / stock / details) into a
 * single PATCH /retailer/offers/{id} that accepts a partial update body.
 *
 * Returns the parsed ProcessStatus response so callers can poll it.
 */
class BolOfferUpdater
{
    public function __construct(private readonly BolPayloadBuilder $builder) {}

    public function update(BolApiClient $apiClient, Product $product, string $reference, string $deliveryCode): ?array
    {
        return $apiClient->patch('/retailer/offers/'.$reference, $this->builder->patchOffer($product, $deliveryCode));
    }
}
