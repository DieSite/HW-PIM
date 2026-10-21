<?php

namespace Webkul\WooCommerce\Listeners;

use App\Jobs\Middleware\DisconnectsIdleRedis;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Webkul\WooCommerce\Services\WooCommerceService;
use Webkul\WooCommerce\Traits\DataTransferMappingTrait;

class DeleteProductFromWooCommerce implements ShouldQueue
{
    use DataTransferMappingTrait, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ACTION_GET = 'getAllProduct';

    public const DELETE_PRODUCT = 'deleteProduct';

    public const DELETE_PRODUCT_VARIANT = 'deleteProductVariant';

    public $tries = 5;

    public $timeout = 300;

    protected $batch;

    protected $connectorService;

    protected $credential;

    /**
     * Create a new job instance.
     */
    public function __construct($batch)
    {
        $this->batch = $batch;
    }

    /**
     * Execute the job.
     */
    /**
     * May run past the Redis idle timeout without issuing a Redis command of
     * its own, so the delete() that retires it on success would find a closed
     * socket. {@see DisconnectsIdleRedis}
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new DisconnectsIdleRedis()];
    }

    public function handle()
    {
        $this->connectorService = app(WooCommerceService::class);

        $this->credential = $this->connectorService->getCredentialForQuickExport();
        if (! $this->credential) {
            return Log::error('No default credentials set for quick export.');
        }

        // Retrieve quick settings
        $quickSettings = $this->credential['extras']['quicksettings'] ?? [];

        if (empty($quickSettings['auto_sync'])) {
            return Log::warning('Auto-sync setting is disabled. Product cannot be deleted.');
        }

        // Check if product exists via SKU; this also finds variations.
        $existingProduct = $this->connectorService->requestApiAction('getProductWithSku', [], ['sku' => $this->batch]);

        if (! isset($existingProduct[0]['id'])) {
            return Log::info("Product with sku $this->batch does not exist at woocommerce end.");
        }

        $found = $existingProduct[0];
        $apiParams = ['credential' => $this->credential['id'], 'id' => $found['id']];

        // The products endpoint refuses variations, and variations cannot be
        // trashed, so a variation is deleted for good through its own endpoint.
        if (($found['type'] ?? null) === 'variation' && ! empty($found['parent_id'])) {
            $result = $this->connectorService->requestApiAction(self::DELETE_PRODUCT_VARIANT, [], $apiParams + ['product' => $found['parent_id'], 'force' => 'true']);
        } else {
            $result = $this->connectorService->requestApiAction(self::DELETE_PRODUCT, [], $apiParams);
        }

        if (($result['id'] ?? null) != $found['id']) {
            Log::error("Deleting sku $this->batch from WooCommerce failed.", ['response' => $result]);

            throw new \RuntimeException("Deleting sku $this->batch from WooCommerce failed: ".json_encode($result));
        }

        return Log::info("Product with sku $this->batch deleted successfully.");
    }
}
