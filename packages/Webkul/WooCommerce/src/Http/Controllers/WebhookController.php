<?php

namespace Webkul\WooCommerce\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Webkul\WooCommerce\Helpers\Webhook\ProcessWooCommerceWebhook;

class WebhookController
{
    /**
     * Handle WooCommerce Webhook
     */
    public function handleWebhook(Request $request)
    {
        Log::info('WooCommerce Webhook Received: \n');

        ProcessWooCommerceWebhook::dispatch([
            'line_items' => collect($request->input('line_items', []))
                ->map(fn (array $item): array => Arr::only($item, ['product_id', 'sku', 'quantity']))
                ->all(),
        ]);

        return response()->json(['status' => 'success'], 200);
    }
}
