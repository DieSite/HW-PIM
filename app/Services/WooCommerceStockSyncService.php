<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Webkul\Product\Models\Product;
use Webkul\WooCommerce\Services\WooCommerceService;

/**
 * Pushes stock-only updates to the lightweight WooCommerce endpoint
 * (POST {shopUrl}/wp-json/diesite/v1/stock) instead of re-syncing the
 * entire product. Used by the Eurogros import to keep stock fast.
 */
class WooCommerceStockSyncService
{
    public const ENDPOINT_PATH = '/wp-json/diesite/v1/stock';

    public const BATCH_SIZE = 1000;

    public const MAX_ATTEMPTS = 3;

    public const RETRY_BACKOFF_MS = 250_000;

    public function __construct(protected WooCommerceService $wooCommerceService) {}

    /**
     * Build a single stock update payload from a product, mirroring the stock
     * calculation in WooCommerce\Helpers\Exporters\Product\Exporter::formatData().
     *
     * @return array{sku: ?string, stock_quantity: int, stock_status: string}
     */
    public static function buildStockUpdate(Product $product): array
    {
        return self::stockUpdateFromValues($product->sku, $product->values ?? []);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{sku: ?string, stock_quantity: int, stock_status: string}
     */
    public static function stockUpdateFromValues(?string $sku, array $values): array
    {
        $common = $values['common'] ?? [];

        $quantity = (int) ($common['voorraad_eurogros'] ?? 0)
            + (int) ($common['voorraad_5_korting_handmatig'] ?? 0)
            + (int) ($common['voorraad_hw_5_korting'] ?? 0);

        return [
            'sku'            => $sku,
            'stock_quantity' => $quantity,
            'stock_status'   => $quantity > 0 ? 'instock' : 'onbackorder',
        ];
    }

    /**
     * Send stock updates to WooCommerce in batches of at most BATCH_SIZE.
     *
     * @param  array<int, array{sku: ?string, stock_quantity: int, stock_status: string}>  $updates
     */
    public function pushUpdates(array $updates): void
    {
        $updates = array_values(array_filter($updates, fn (array $update): bool => ! empty($update['sku'])));

        if (empty($updates)) {
            return;
        }

        $credential = $this->resolveCredential();

        if (is_null($credential)) {
            Log::error('WooCommerce stock sync: no default credential configured.');

            return;
        }

        $endpoint = self::resolveEndpoint($credential['shopUrl']);

        foreach (array_chunk($updates, self::BATCH_SIZE) as $batch) {
            $this->sendBatch($endpoint, $credential, $batch);
        }
    }

    public static function resolveEndpoint(string $shopUrl): string
    {
        $url = str_replace(['/wp-admin', 'shop/'], '', $shopUrl);

        return rtrim($url, '/').self::ENDPOINT_PATH;
    }

    /**
     * @return array{shopUrl: string, consumerKey: string, consumerSecret: string}|null
     */
    protected function resolveCredential(): ?array
    {
        $credential = Cache::remember(
            'wc_default_credential',
            300,
            fn () => $this->wooCommerceService->getCredentialForQuickExport()
        );

        if (empty($credential['shopUrl']) || empty($credential['consumerKey']) || empty($credential['consumerSecret'])) {
            return null;
        }

        return $credential;
    }

    /**
     * @param  array{shopUrl: string, consumerKey: string, consumerSecret: string}  $credential
     * @param  array<int, array{sku: ?string, stock_quantity: int, stock_status: string}>  $batch
     */
    protected function sendBatch(string $endpoint, array $credential, array $batch): void
    {
        $attempt = 0;
        $response = null;

        do {
            $attempt++;

            $response = Http::withBasicAuth($credential['consumerKey'], $credential['consumerSecret'])
                ->acceptJson()
                ->timeout(60)
                ->post($endpoint, ['updates' => $batch]);

            if ($response->serverError() && $attempt < self::MAX_ATTEMPTS) {
                usleep(self::RETRY_BACKOFF_MS);

                continue;
            }

            break;
        } while ($attempt < self::MAX_ATTEMPTS);

        $this->logResult($response, count($batch));
    }

    protected function logResult(Response $response, int $batchCount): void
    {
        if ($response->failed()) {
            Log::error('WooCommerce stock sync request failed.', [
                'status' => $response->status(),
                'body'   => $response->body(),
                'count'  => $batchCount,
            ]);

            return;
        }

        $summary = $response->json() ?? [];

        if (($summary['failed'] ?? 0) > 0) {
            $failedItems = collect($summary['items'] ?? [])
                ->where('status', 'failed')
                ->values()
                ->all();

            Log::warning('WooCommerce stock sync: some items failed.', [
                'failed' => $summary['failed'],
                'items'  => $failedItems,
            ]);
        }
    }
}
