<?php

namespace App\Services;

use App\Clients\BolApiClient;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Product\Models\Product;
use Webkul\Product\Repositories\ProductRepository;

class BolComProductService
{
    public function __construct(protected ProductRepository $productRepository) {}

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function syncProduct(Product $product, $previousSyncState = false)
    {
        try {
            if (! $product->bol_com_sync) {
                if ($previousSyncState && $product->bol_com_reference) {
                    $credentialId = $product->bol_com_credential_id ?: $this->getCredentialsId();
                    $apiClient = new BolApiClient($credentialId);
                    $this->deleteProductFromBolCom($product, $apiClient);
                }

                return;
            }

            if (! $product->bol_com_credential_id) {
                Log::warning('No Bol.com credential selected for product sync', ['product_id' => $product->id]);

                return;
            }

            $apiClient = new BolApiClient($product->bol_com_credential_id);

            if (! $previousSyncState || ! $product->bol_com_reference) {
                $this->createProductOnBolCom($product, $apiClient);
            } else {
                $this->updateProductOnBolCom($product, $apiClient);
            }
        } catch (Exception $e) {
            Log::error('Failed to sync product with Bol.com', [
                'product_id' => $product->id,
                'sku'        => $product->sku,
                'error'      => $e->getMessage(),
            ]);

            throw new Exception('Failed to sync with Bol.com: '.$e->getMessage());
        }
    }

    /**
     * @throws GuzzleException
     */
    protected function createProductOnBolCom(Product $product, BolApiClient $apiClient)
    {
        $data = $this->buildProductData($product);

        $response = $apiClient->post('/retailer/offers', $data);

        if (! empty($response['entityId'])) {
            $product->bol_com_reference = $response['entityId'];
            $this->productRepository->update(['bol_com_reference' => $response['entityId']], $product->id);

            Log::info('Product created on Bol.com', [
                'product_id'    => $product->id,
                'bol_reference' => $response['entityId'],
            ]);
        }
    }

    protected function updateProductOnBolCom(Product $product, BolApiClient $apiClient)
    {
        $data = $this->buildProductData($product);

        $apiClient->put('/retailer/offers/'.$product->bol_com_reference, $data);

        Log::info('Product updated on Bol.com', [
            'product_id'    => $product->id,
            'bol_reference' => $product->bol_com_reference,
        ]);
    }

    protected function deleteProductFromBolCom(Product $product, BolApiClient $apiClient)
    {
        $apiClient->delete('/retailer/offers/'.$product->bol_com_reference);

        $this->productRepository->update([
            'bol_com_reference' => null,
        ], $product->id);

        Log::info('Product deleted from Bol.com', [
            'product_id'             => $product->id,
            'previous_bol_reference' => $product->bol_com_reference,
        ]);
    }

    protected function buildProductData(Product $product)
    {
        $ean = $product->values['common']['ean'];
        $sku = $product->sku;
        $title = $product->values['common']['productnaam'];
        $priceData = $product->values['common']['prijs'] ?? [];
        $price = isset($priceData['EUR']) ? (float) $priceData['EUR'] : 0;

        $stock = (int) $product->values['common']['voorraad_eurogros'];

        return [
            'ean'              => $ean,
            'condition'        => [
                'name' => 'NEW',
            ],
            'reference'           => $sku,
            'onHoldByRetailer'    => false,
            'unknownProductTitle' => $title,
            'pricing'             => [
                'bundlePrices' => [
                    [
                        'quantity'  => 1,
                        'unitPrice' => 219.00, //TODO prijs fixen
                    ],
                ],
            ],
            'stock' => [
                'amount'            => $stock,
                'managedByRetailer' => true,
            ],
            'fulfilment' => [
                'method'       => 'FBR',
                'deliveryCode' => '1-8d',
            ],
        ];
    }

    protected function getCredentialsId()
    {
        $credential = DB::table('bol_com_credentials')
            ->where('is_active', 1)
            ->first();

        return $credential?->id;
    }

    public function getCredentialsOptions(): array
    {
        $credentials = DB::table('bol_com_credentials')
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        $options = [];
        foreach ($credentials as $credential) {
            $options[$credential->id] = $credential->name;
        }

        return $options;
    }
}
