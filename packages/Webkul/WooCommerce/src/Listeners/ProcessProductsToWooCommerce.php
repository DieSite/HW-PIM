<?php

namespace Webkul\WooCommerce\Listeners;

use App\Exceptions\WoocommerceProductSkuExistsException;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\WooCommerce\Helpers\Exporters\Product\Exporter;
use Webkul\WooCommerce\Repositories\DataTransferMappingRepository;
use Webkul\WooCommerce\Services\WooCommerceService;
use Webkul\WooCommerce\Traits\DataTransferMappingTrait;

class ProcessProductsToWooCommerce implements ShouldQueue
{
    use DataTransferMappingTrait, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ACTION_ADD = 'addProduct';

    public const ACTION_UPDATE = 'updateProduct';

    public const ACTION_GET = 'getAllProduct';

    public const ACTION_ADD_VARIATION = 'addVariation';

    public const ACTION_UPDATE_VARIATION = 'updateVariation';

    public $tries = 5;

    public $timeout = 300000;

    protected $batch;

    protected $exporter;

    protected $export = null;

    protected $connectorService;

    protected $credential;

    protected $dataTransferMappingRepository;

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
        $this->exporter->setMediaExport(true);

        // Prepare product data
        $this->batch['code'] = $this->batch['sku'];
        $this->batch['type'] = ! empty($this->batch['variants']) ? 'variable' : 'simple';
        $productData = $this->formatData($this->batch);

        if (! isset($productData['sku'])) {
            Log::debug('Product data', ['product_data' => $productData]);
        }

        try {
            if (isset($productData['parent_id'])) {
                $this->processToVariation($productData);
            } else {
                $this->processToParentProduct($productData);
            }
        } catch (WoocommerceProductSkuExistsException $e) {
            $product = Product::whereSku($e->sku)->first();
            $additional = $product->additional;
            $additional['product_sku_already_exists'] = $e->externalId;
            $product->additional = $additional;
            $product->save();
        } catch (\Exception $e) {
            $product = Product::whereSku($e->sku)->first();
            $additional = $product->additional;
            $additional['product_sync_error'] = $e->getMessage();
            $product->additional = $additional;
            $product->save();
            throw $e;
        }
    }

    protected function formatData($batchData)
    {
        return $this->exporter->formatData($batchData);
    }

    /**
     * @throws \Exception
     * @throws WoocommerceProductSkuExistsException
     */
    private function processToVariation(array $productData)
    {
        Log::debug('Processing to variation');
        $productRepository = app(ProductRepository::class);
        $parent = $productRepository->find($productData['parent_id']);

        $parentProduct = $this->connectorService->requestApiAction(
            'getProductWithSku',
            [],
            ['sku' => $parent->sku]
        );

        if (! isset($parentProduct[0])) {
            Log::debug('Parent product not found.');

            return;
        }

        $existingProduct = $this->connectorService->requestApiAction(
            'getVariation',
            [],
            ['sku' => $productData['sku'], 'product' => $parentProduct[0]['id']]
        );

        if (! isset($existingProduct[0])) {
            $result = $this->connectorService->requestApiAction(
                self::ACTION_ADD_VARIATION,
                $productData,
                ['credential' => $this->credential['id'], 'product' => $parentProduct[0]['id']]
            );
        } else {
            $result = $this->connectorService->requestApiAction(
                self::ACTION_UPDATE_VARIATION,
                $productData,
                ['credential' => $this->credential['id'], 'product' => $parentProduct[0]['id'], 'id' => $existingProduct[0]['id']]
            );
        }

        if ($result['code'] == 200) {
            Log::info("Product $productData[sku] updated successfully");
        } elseif ($result['code'] == 201) {
            Log::info("Product $productData[sku] created successfully");
        } elseif ($result['code'] == 400) {
            if ($result['message'] === 'Ongeldig of dubbel artikelnummer.') {
                throw new WoocommerceProductSkuExistsException($result['data']['resource_id'], $productData['sku']);
            } else {
                throw new \Exception('Error occurred (400): '.json_encode($result));
            }
        } else {
            throw new \Exception("Error occurred ($result[code]): ".json_encode($result));
        }
    }

    private function processToParentProduct(array $productData)
    {
        // Check if product exists via SKU
        $existingProduct = $this->connectorService->requestApiAction(
            'getProductWithSku',
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
            Log::debug("Product $productData[sku] updated successfully");
        } elseif ($result['code'] == 201) {
            Log::debug("Product $productData[sku] created successfully");
        } elseif ($result['code'] == 400) {
            if ($result['message'] === 'Ongeldig of dubbel artikelnummer.') {
                throw new WoocommerceProductSkuExistsException($result['data']['resource_id'], $productData['sku']);
            } else {
                throw new \Exception("Error occurred ($result[code]): ".json_encode($result));
            }
        } else {
            throw new \Exception("Error occurred ($result[code]): ".json_encode($result));
        }
    }
}
