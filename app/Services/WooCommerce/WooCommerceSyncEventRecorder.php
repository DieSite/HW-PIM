<?php

namespace App\Services\WooCommerce;

use App\Enums\WooCommerceSyncEventStatus;
use App\Models\WooCommerceSyncEvent;
use Illuminate\Support\Facades\Log;
use Webkul\Product\Models\Product;

class WooCommerceSyncEventRecorder
{
    public function record(
        Product $product,
        WooCommerceSyncEventStatus $status,
        ?string $action = null,
        ?string $message = null,
        ?string $customerMessage = null,
        ?string $externalId = null,
        ?array $payload = null,
    ): WooCommerceSyncEvent {
        $event = WooCommerceSyncEvent::create([
            'product_id'       => $product->id,
            'action'           => $action,
            'status'           => $status,
            'message'          => $message,
            'customer_message' => $customerMessage,
            'external_id'      => $externalId,
            'payload'          => $payload,
        ]);

        Log::info('WooCommerce sync event', [
            'product_id'  => $product->id,
            'sku'         => $product->sku,
            'action'      => $action,
            'status'      => $status->value,
            'external_id' => $externalId,
            'message'     => $message,
        ]);

        return $event;
    }
}
