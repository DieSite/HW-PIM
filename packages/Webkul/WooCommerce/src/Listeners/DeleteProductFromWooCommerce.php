<?php

namespace Webkul\WooCommerce\Listeners;

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

    public $tries = 5;

    public $timeout = 300000;

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

        // Check if product exists via SKU
        $existingProduct = $this->connectorService->requestApiAction('getProductWithSku', [], ['sku' => $this->batch]);

        if (! isset($existingProduct[0])) {
            return Log::info("Product with sku $this->batch does not exist at woocommerce end.");
        } else {
            $apiParams = ['credential' => $this->credential['id']];
            $apiParams['id'] = $existingProduct[0]['id'];
            $result = $this->connectorService->requestApiAction(self::DELETE_PRODUCT, [], $apiParams);

            return Log::info("Product with sku $this->batch deleted successfully.");
        }
    }
}
