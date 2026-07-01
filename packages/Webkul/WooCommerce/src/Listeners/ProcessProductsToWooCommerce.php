<?php

namespace Webkul\WooCommerce\Listeners;

use App\Enums\WooCommerceSyncEventStatus;
use App\Exceptions\ParentHasNoVariantsException;
use App\Exceptions\WoocommerceBadGatewayException;
use App\Exceptions\WoocommerceProductExistsAsVariationException;
use App\Exceptions\WoocommerceProductSkuExistsException;
use App\Exceptions\WoocommerceTimeoutException;
use App\Models\Product;
use App\Services\WooCommerce\WooCommerceSyncEventRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Sentry;
use Webkul\WooCommerce\DTO\ProductBatch;
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

    public $timeout = 600;

    protected ProductBatch $batch;

    protected Exporter $exporter;

    protected WooCommerceService $connectorService;

    protected DataTransferMappingRepository $dataTransferMappingRepository;

    protected WooCommerceSyncEventRecorder $eventRecorder;

    protected ?array $credential = null;

    /**
     * Create a new job instance.
     */
    public function __construct(ProductBatch $batch)
    {
        $this->batch = $batch;
    }

    /**
     * Execute the job.
     */
    public function handle(Exporter $exporter, WooCommerceService $connectorService, DataTransferMappingRepository $dataTransferMappingRepository, WooCommerceSyncEventRecorder $eventRecorder): void
    {
        $this->exporter = $exporter;
        $this->connectorService = $connectorService;
        $this->dataTransferMappingRepository = $dataTransferMappingRepository;
        $this->eventRecorder = $eventRecorder;

        // Retrieve credential — cached to avoid a DB hit on every job during bulk syncs.
        // Invalidated by the Credential model observer when credentials are saved.
        $this->credential = Cache::remember('wc_default_credential', 300, fn () => $this->connectorService->getCredentialForQuickExport());
        if (! $this->credential) {
            Log::error('No default credentials set for quick export.');

            return;
        }

        // Retrieve quick settings
        $quickSettings = $this->credential['extras']['quicksettings'] ?? [];
        if (empty($quickSettings['auto_sync'])) {
            Log::warning('Auto-sync setting is disabled. Product cannot be synced.');

            return;
        }

        if (! isset($quickSettings['quick_channel'], $quickSettings['quick_locale'], $quickSettings['quick_currency'])) {
            Log::error('Quick export settings are incomplete in the default credentials.');

            return;
        }

        // Initialize exporter
        $this->exporter->credential = $this->credential;
        $this->exporter->initMappingsAndAttribute();
        $this->exporter->locale = $quickSettings['quick_locale'];
        $this->exporter->channel = $quickSettings['quick_channel'];
        $this->exporter->currency = $quickSettings['quick_currency'];
        $this->exporter->setMediaExport(true);

        $productData = null;
        $product = Product::whereSku($this->batch->sku)->first();
        $additional = $product->additional;
        unset($additional['product_sync_error']);
        if (is_array($additional) && count($additional) === 0) {
            $additional = null;
        }
        $product->additional = $additional;
        $product->save();

        $this->eventRecorder->record(
            $product,
            WooCommerceSyncEventStatus::Started,
            'sync',
            'Synchronisatie met WooCommerce gestart.',
            'Synchronisatie met WooCommerce gestart.'
        );

        try {
            $productData = $this->formatData($this->batch);

            if (isset($productData['parent_id'])) {
                $this->processToVariation($productData);
            } else {
                $this->processToParentProduct($productData);
            }
        } catch (WoocommerceProductSkuExistsException $e) {
            Log::info("SKU conflict for {$e->sku} (WP ID: {$e->externalId}). Deleting conflicting product and retrying.");
            $this->deleteAndRetry(
                fn () => $this->deleteConflictingWooCommerceProduct($e->externalId, $e->sku),
                $e->sku,
                $productData,
                'product_sku_already_exists',
                $e->externalId
            );
        } catch (WoocommerceProductExistsAsVariationException $e) {
            Log::info("Product {$e->sku} exists as a variation in WooCommerce. Finding and deleting conflicting product to retry.");
            $this->deleteAndRetry(
                fn () => $this->findAndDeleteConflictingWooCommerceProductBySku($e->sku),
                $e->sku,
                $productData,
                'product_sync_error',
                $e->getMessage()
            );
        } catch (WoocommerceTimeoutException|WoocommerceBadGatewayException $e) {
            $product = Product::whereSku($this->batch->sku)->first();
            $additional = $product->additional;
            $additional['product_sync_error'] = $e->getMessage();
            $product->additional = $additional;
            $product->save();
            $this->recordFailure($product, $e->getMessage());
            throw $e;
        } catch (ParentHasNoVariantsException $e) {
            $product = Product::whereSku($this->batch->sku)->first();
            $additional = $product->additional;
            $additional['product_sync_error'] = $e->getMessage();
            $product->additional = $additional;
            $product->save();
            $this->recordFailure($product, $e->getMessage());
        } catch (\Exception $e) {
            $product = Product::whereSku($this->batch->sku)->first();
            $additional = $product->additional;
            $errorMessage = str_contains($e->getMessage(), 'zoektabel')
                ? "SKU {$this->batch->sku} bestaat al in de WooCommerce zoektabel. Het product staat waarschijnlijk in de prullenbak van WooCommerce. Verwijder het daar eerst definitief en probeer opnieuw."
                : $e->getMessage();
            $additional['product_sync_error'] = $errorMessage;
            $product->additional = $additional;
            $product->save();
            $this->recordFailure($product, $errorMessage, $e->getMessage());
            Sentry::captureException($e);
            throw $e;
        }
    }

    protected function formatData(ProductBatch $batch): array
    {
        return $this->exporter->formatData($batch);
    }

    /**
     * Records a failed WooCommerce sync event for the product currently being synced.
     */
    private function recordFailure(?Product $product, string $customerMessage, ?string $message = null): void
    {
        if (! $product) {
            return;
        }

        $this->eventRecorder->record(
            $product,
            WooCommerceSyncEventStatus::Failed,
            'sync',
            $message ?? $customerMessage,
            $customerMessage
        );
    }

    /**
     * Records a successful WooCommerce sync event, storing the remote product ID.
     */
    private function recordSuccess(string $sku, string $customerMessage, mixed $externalId = null): void
    {
        $product = Product::whereSku($sku)->first();

        if (! $product) {
            return;
        }

        $this->eventRecorder->record(
            $product,
            WooCommerceSyncEventStatus::Success,
            'sync',
            $customerMessage,
            $customerMessage,
            $externalId !== null ? (string) $externalId : null
        );
    }

    /**
     * @throws \Exception
     * @throws WoocommerceProductSkuExistsException
     */
    private function processToVariation(array $productData): void
    {
        Log::debug('Processing to variation');
        $parent = Product::find($productData['parent_id']);

        $parentMapping = $this->getDataTransferMapping($parent->sku, 'product');
        $parentExternalId = $parentMapping[0]['externalId'] ?? null;

        if (! $parentExternalId) {
            // Parent not in local mapping — look it up in WooCommerce
            $parentProduct = $this->connectorService->requestApiAction(
                'getProductWithSku',
                [],
                ['sku' => $parent->sku]
            );

            if (! isset($parentProduct[0])) {
                Log::debug('Parent product not found.');

                return;
            }

            $parentExternalId = $parentProduct[0]['id'];
        }

        $variationMapping = $this->getDataTransferMapping($productData['sku'], 'product');
        $variationExternalId = $variationMapping[0]['externalId'] ?? null;

        if ($variationExternalId) {
            // Both IDs known — update directly without any lookup
            $result = $this->connectorService->requestApiAction(
                self::ACTION_UPDATE_VARIATION,
                $productData,
                ['credential' => $this->credential['id'], 'product' => $parentExternalId, 'id' => $variationExternalId]
            );
        } else {
            // Variation not in local mapping — check WooCommerce
            $existingVariation = $this->connectorService->requestApiAction(
                'getVariation',
                [],
                ['sku' => $productData['sku'], 'product' => $parentExternalId]
            );

            if (! isset($existingVariation[0])) {
                $result = $this->connectorService->requestApiAction(
                    self::ACTION_ADD_VARIATION,
                    $productData,
                    ['credential' => $this->credential['id'], 'product' => $parentExternalId]
                );
            } else {
                $result = $this->connectorService->requestApiAction(
                    self::ACTION_UPDATE_VARIATION,
                    $productData,
                    ['credential' => $this->credential['id'], 'product' => $parentExternalId, 'id' => $existingVariation[0]['id']]
                );
            }
        }

        $this->handleWoocommerceResponse($result, $productData);
    }

    /**
     * @throws WoocommerceProductSkuExistsException
     */
    private function processToParentProduct(array $productData): void
    {
        $mapping = $this->getDataTransferMapping($productData['sku'], 'product');
        $externalId = $mapping[0]['externalId'] ?? null;

        if ($externalId) {
            // Already known — update directly without a lookup call
            $result = $this->connectorService->requestApiAction(
                self::ACTION_UPDATE,
                $productData,
                ['credential' => $this->credential['id'], 'id' => $externalId]
            );
        } else {
            // Not in local mapping — check WooCommerce (may have been created outside PIM)
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
        }

        $this->handleWoocommerceResponse($result, $productData);
    }

    private function deleteAndRetry(callable $deleteFn, string $sku, ?array $productData, string $errorKey, mixed $errorValue): void
    {
        try {
            $deleteFn();

            if (isset($productData['parent_id'])) {
                $this->processToVariation($productData);
            } else {
                $this->processToParentProduct($productData);
            }

            $product = Product::whereSku($sku)->first();
            if ($product) {
                $additional = $product->additional ?? [];
                unset($additional[$errorKey]);
                if (count($additional) === 0) {
                    $additional = null;
                }
                $product->additional = $additional;
                $product->save();
            }

            Log::info("Re-sync successful for {$sku}.");
        } catch (\Exception $retryException) {
            Log::error("Auto-delete and retry failed for {$sku}: ".$retryException->getMessage());
            Sentry::captureException($retryException);

            $product = Product::whereSku($sku)->first();
            if ($product) {
                $additional = $product->additional ?? [];
                $additional[$errorKey] = $errorValue;
                $product->additional = $additional;
                $product->save();
                $this->recordFailure($product, (string) $errorValue, $retryException->getMessage());
            }
        }
    }

    private function findAndDeleteConflictingWooCommerceProductBySku(string $sku): void
    {
        $credentialId = $this->credential['id'];

        $results = $this->connectorService->requestApiAction(
            'getProductWithSku',
            [],
            ['sku' => $sku, 'credential' => $credentialId]
        );

        if (! isset($results[0])) {
            throw new \Exception("Could not find conflicting WooCommerce product with SKU {$sku}.");
        }

        $found = $results[0];

        // If the result is a variation it will have a parent_id; otherwise use its own id
        $productId = (! empty($found['parent_id']) && $found['parent_id'] > 0)
            ? (string) $found['parent_id']
            : (string) $found['id'];

        $this->deleteConflictingWooCommerceProduct($productId);
    }

    private function deleteConflictingWooCommerceProduct(string $externalId, string $fallbackSku = ''): void
    {
        $credentialId = $this->credential['id'];

        $wpProduct = $this->connectorService->requestApiAction(
            'getProduct',
            [],
            ['id' => $externalId, 'credential' => $credentialId]
        );

        // Fetch failed — fall back to SKU-based search
        if (empty($wpProduct['id'])) {
            if ($fallbackSku) {
                $this->findAndDeleteConflictingWooCommerceProductBySku($fallbackSku);
            }

            return;
        }

        // The ID belongs to a variation — delete its parent instead
        if (! empty($wpProduct['parent_id']) && $wpProduct['parent_id'] > 0) {
            $this->deleteConflictingWooCommerceProduct((string) $wpProduct['parent_id'], $fallbackSku);

            return;
        }

        if (($wpProduct['type'] ?? '') === 'variable') {
            $variations = $this->connectorService->requestApiAction(
                'getVariation',
                [],
                ['product' => $externalId, 'credential' => $credentialId]
            );

            if (is_array($variations)) {
                foreach ($variations as $variation) {
                    if (isset($variation['id'])) {
                        $this->connectorService->requestApiAction(
                            'deleteProductVariant',
                            [],
                            ['product' => $externalId, 'id' => $variation['id'], 'credential' => $credentialId, 'force' => 'true']
                        );
                    }
                }
            }
        }

        $this->connectorService->requestApiAction(
            'deleteProduct',
            [],
            ['id' => $externalId, 'credential' => $credentialId, 'force' => 'true']
        );

        Log::info("Deleted WooCommerce product ID {$externalId} (and its variations if any).");
    }

    /**
     * @throws WoocommerceProductSkuExistsException
     * @throws \Exception
     */
    private function handleWoocommerceResponse(array $result, array $productData): void
    {
        if ($result['code'] === 0) {
            throw new WoocommerceTimeoutException($productData['sku'], $result['error'] ?? 'unknown curl error');
        } elseif ($result['code'] === 200) {
            Log::debug("Product $productData[sku] updated successfully");
            $this->recordSuccess($productData['sku'], 'Bijgewerkt in WooCommerce.', $result['id'] ?? null);
        } elseif ($result['code'] === 201) {
            Log::debug("Product $productData[sku] created successfully");
            $this->recordSuccess($productData['sku'], 'Aangemaakt in WooCommerce.', $result['id'] ?? null);
        } elseif ($result['code'] === 400) {
            if ($result['message'] === 'Ongeldig of dubbel artikelnummer.') {
                throw new WoocommerceProductSkuExistsException($result['data']['resource_id'], $productData['sku']);
            } elseif (str_contains($result['message'], 'Ongeldige parameter(s):') && isset($result['data']['details']['default_attributes']['data']['param'])) {
                $param = $result['data']['details']['default_attributes']['data']['param'];
                if ($param === 'default_attributes[0][option]') {
                    throw new ParentHasNoVariantsException($productData['sku']);
                }
            } else {
                throw new \Exception("Error occurred ($result[code]): ".json_encode($result));
            }
        } else {
            if ($result['code'] === 502) {
                throw new WoocommerceBadGatewayException($productData['sku']);
            }

            if ($result['code'] === 500) {
                $errorMessage = $result['data']['error']['message'] ?? '';

                if (str_starts_with($errorMessage, 'Uncaught Exception: Ongeldig product. ')) {
                    throw new WoocommerceProductExistsAsVariationException($productData['sku']);
                }
            }

            // Acts as an "else" case for both if-statements.
            throw new \Exception("Error occurred ($result[code]): ".json_encode($result));
        }
    }
}
