<?php

namespace Webkul\WooCommerce\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Webkul\WooCommerce\Helpers\Webhook\ProcessWooCommerceWebhook;

class WebhookController
{
    /**
     * Handle WooCommerce Webhook
     */
    public function handleWebhook(Request $request)
    {
        $data = $request->all() ?? [];
        Log::info('WooCommerce Webhook Received: \n');

        // Dispatch job to process the webhook data
        ProcessWooCommerceWebhook::dispatch($data);

        return response()->json(['status' => 'success'], 200);
    }
}
