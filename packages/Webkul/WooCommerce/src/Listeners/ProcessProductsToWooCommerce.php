<?php

namespace Webkul\WooCommerce\Listeners;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Webkul\WooCommerce\Helpers\Exporters\Product\Exporter;
use Webkul\WooCommerce\Repositories\DataTransferMappingRepository;
use Webkul\WooCommerce\Services\WooCommerceService;
use Webkul\WooCommerce\Traits\DataTransferMappingTrait;

class ProcessProductsToWooCommerce implements ShouldQueue
{
    use DataTransferMappingTrait, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public $timeout = 300000;

    protected $batch;

    protected $exporter;

    protected $export = null;

    protected $connectorService;

    protected $credential;

    protected $dataTransferMappingRepository;

    public const ACTION_ADD = 'addProduct';

    public const ACTION_UPDATE = 'updateProduct';

    public const ACTION_GET = 'getAllProduct';

    public const ACTION_ADD_VARIATION = 'addVariation';

    public const ACTION_UPDATE_VARIATION = 'updateVariation';

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
        // Initialize dependencies
        $this->exporter = app(Exporter::class);
        $this->connectorService = app(WooCommerceService::class);
        $this->dataTransferMappingRepository = app(DataTransferMappingRepository::class);

        // Retrieve credential
        $this->credential = $this->connectorService->getCredentialForQuickExport();
        if (! $this->credential) {
            return Log::error('No default credentials set for quick export.');
        }

        // Retrieve quick settings
        $quickSettings = $this->credential['extras']['quicksettings'] ?? [];
        if (empty($quickSettings['auto_sync'])) {
            return Log::warning('Auto-sync setting is disabled. Product cannot be synced.');
        }

        if (! isset($quickSettings['quick_channel'], $quickSettings['quick_locale'], $quickSettings['quick_currency'])) {
            return Log::error('Quick export settings are incomplete in the default credentials.');
        }

        // Initialize exporter
        $this->exporter->credential = $this->credential;
        $this->exporter->initMappingsAndAttribute();
        $this->exporter->locale = $quickSettings['quick_locale'];
        $this->exporter->channel = $quickSettings['quick_channel'];
        $this->exporter->currency = $quickSettings['quick_currency'];

        // Prepare product data
        $this->batch['code'] = $this->batch['sku'];
        $this->batch['type'] = ! empty($this->batch['variants']) ? 'variable' : 'simple';
        $productData = $this->formatData($this->batch);

        // Check if product exists via SKU
        $existingProduct = $this->connectorService->requestApiAction(
            'products',
            [],
            ['sku' => $productData['sku']]
        );

        if (! isset($existingProduct[0])) {
            $result = $this->connectorService->requestApiAction(
                self::ACTION_ADD,
                $productData,
                ['credential' => $this->credential['id']]
            );
        } else {
            $result = $this->connectorService->requestApiAction(
                self::ACTION_UPDATE,
                $productData,
                ['credential' => $this->credential['id'], 'id' => $existingProduct[0]['id']]
            );
        }

        if ($result['code'] == 200) {
            Log::info("Product updated successfully \n ".json_encode($result));
        } elseif ($result['code'] == 201) {
            Log::info("Product created successfully \n ".json_encode($result));
        } else {
            Log::error('Error occured'.json_encode($result));
        }
    }

    protected function formatData($batchData)
    {
        return $this->exporter->formatData($batchData);
    }
}
