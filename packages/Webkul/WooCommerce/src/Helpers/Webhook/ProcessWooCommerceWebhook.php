<?php

namespace Webkul\WooCommerce\Helpers\Webhook;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Webkul\Product\Repositories\ProductRepository as RepositoriesProductRepository;
use Webkul\WooCommerce\Repositories\CredentialRepository;
use Webkul\WooCommerce\Repositories\DataTransferMappingRepository;

class ProcessWooCommerceWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $webhookData;

    /**
     * Create a new job instance.
     */
    public function __construct($webhookData)
    {
        $this->webhookData = $webhookData;
    }

    /**
     * Execute the job.
     */
    public function handle(RepositoriesProductRepository $productRepository, CredentialRepository $credentialRepository, DataTransferMappingRepository $dataTransferMappingRepository)
    {
        // Get active credentials
        $activeCredentials = $credentialRepository->findWhere(['active' => 1]);

        // Find the first mapped stock_quantity attribute
        $attributeMapped = collect($activeCredentials)->firstWhere('extras.settings.stock_quantity')['extras']['settings']['stock_quantity'] ?? null;

        if (! $attributeMapped) {
            Log::error('No attribute is mapped for stock_quantity.');

            return;
        }

        foreach ($this->webhookData['line_items'] ?? [] as $item) {
            $productId = $item['product_id'];
            $sku = ! empty($item['sku']) ? $item['sku'] : null;
            $quantityPurchased = $item['quantity'];

            if (! $sku) {
                $productMapping = $dataTransferMappingRepository->findWhere(['externalId' => $productId])->toArray();

                if (! $productMapping) {
                    Log::error('Product Mapping is not found for the product with id '.$productId);

                    continue;
                }

                $sku = $productMapping[0]['code'];
            }

            $product = $productRepository->findWhere(['sku' => $sku])->first()->toArray();

            if (empty($product)) {
                Log::error('Product with sku '.$productId.' not found.');

                return;
            }
            $id = $product['id'];

            $product['values']['common'][$attributeMapped] = max(0, $product->values['common'][$attributeMapped] - $quantityPurchased);

            // Update product repository
            $productRepository->update($product, $id);
        }
    }
}
