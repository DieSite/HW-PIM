<?php

namespace App\Services\WooCommerce;

use App\Enums\WooCommerceSyncEventStatus;
use App\Models\WooCommerceSyncEvent;
use Illuminate\Support\Facades\Log;
use Webkul\Product\Models\Product;

class WooCommerceSyncEventRecorder
{
    /**
     * Recorded at dispatch time so the timeline shows the sync the moment it is
     * queued, instead of staying on the previous run until a worker picks it up.
     */
    public function queued(Product $product, string $action = 'sync'): WooCommerceSyncEvent
    {
        return $this->record(
            $product,
            WooCommerceSyncEventStatus::Queued,
            $action,
            'In wachtrij geplaatst voor synchronisatie met WooCommerce.',
            'In wachtrij geplaatst voor synchronisatie met WooCommerce.'
        );
    }

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
