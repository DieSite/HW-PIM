<?php

namespace App\Services\WooCommerce;

use App\Enums\WooCommerceAssumption;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Sentry\Severity;
use Sentry\State\Scope;

/**
 * Reports a WooCommerce response that contradicts what the sync assumes.
 *
 * Every violation is logged with its details. Sentry gets one issue per
 * assumption, and at most one event per assumption per alert interval: a
 * bulk sync would otherwise send one event per product the moment the shop
 * changes its behaviour.
 */
class WooCommerceAssumptionMonitor
{
    public const ALERT_INTERVAL_SECONDS = 600;

    /**
     * @param  array<string, mixed>  $context
     */
    public function violated(WooCommerceAssumption $assumption, string $observation, array $context = []): void
    {
        $message = "WooCommerce-aanname geschonden: {$assumption->description()} {$observation} {$assumption->consequence()}";

        Log::warning($message, ['assumption' => $assumption->value] + $context);

        if (! Cache::add("woocommerce-assumption-alerted:{$assumption->value}", true, self::ALERT_INTERVAL_SECONDS)) {
            return;
        }

        \Sentry\withScope(function (Scope $scope) use ($assumption, $message, $context): void {
            $scope->setFingerprint(['woocommerce-assumption', $assumption->value]);
            $scope->setTag('woocommerce.assumption', $assumption->value);
            $scope->setContext('woocommerce_assumption', $context);

            \Sentry\captureMessage($message, Severity::warning());
        });
    }
}
