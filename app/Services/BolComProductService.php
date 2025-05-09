<?php

namespace App\Services;

use App\Clients\BolApiClient;
use App\Models\BolComCredential;
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
    public function syncProduct(Product $product, BolComCredential $bolComCredential, $previousSyncState = false, $unchecked = false)
    {
        try {
            $apiClient = new BolApiClient;
            $apiClient->setCredential($bolComCredential);

            $pivotData = $product->bolComCredentials()
                ->where('bol_com_credentials.id', $bolComCredential->id)
                ->first();

            $reference = $pivotData ? $pivotData->pivot->reference : null;
            $deliveryCode = $pivotData ? $pivotData->pivot->delivery_code : '1-8d';

            if ($unchecked) {
                $this->deleteProductFromBolCom($product, $apiClient, $reference, $bolComCredential);
                return null;
            }

            if (! $product->bol_com_sync) {
                if ($previousSyncState && $reference) {
                    $this->deleteProductFromBolCom($product, $apiClient, $reference, $bolComCredential);
                    $product->bolComCredentials()->detach($bolComCredential->id);
                }

                return null;
            }

            if (! $previousSyncState || ! $reference) {
                return $this->createProductOnBolCom($product, $apiClient, $deliveryCode);
            } else {
                return $this->updateProductOnBolCom($product, $apiClient, $reference, $deliveryCode);
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
    protected function createProductOnBolCom(Product $product, BolApiClient $apiClient, $deliveryCode)
    {
        $data = $this->buildProductData($product, $deliveryCode);

        return $apiClient->post('/retailer/offers', $data);

    }

    /**
     * @throws GuzzleException
     */
    /**
     * @throws GuzzleException
     */
    protected function updateProductOnBolCom(Product $product, BolApiClient $apiClient, $reference, $deliveryCode)
    {
        $this->updateProductPrice($product, $apiClient, $reference);
        $this->updateProductStock($product, $apiClient, $reference);
        $this->updateProductDetails($product, $apiClient, $reference, $deliveryCode);

        return true;
    }

    /**
     * @throws GuzzleException
     */
    protected function updateProductPrice(Product $product, BolApiClient $apiClient, $reference)
    {
        $priceData = $product->values['common']['prijs'] ?? [];
        $price = isset($priceData['EUR']) ? (float) $priceData['EUR'] : 0;
        $price = (float) number_format($price, 2, '.', '');

        $data = [
            'pricing' => [
                'bundlePrices' => [
                    [
                        'quantity'  => 1,
                        'unitPrice' => $price,
                    ],
                ],
            ],
        ];

        return $apiClient->put('/retailer/offers/'.$reference.'/price', $data);
    }

    /**
     * @throws GuzzleException
     */
    protected function updateProductStock(Product $product, BolApiClient $apiClient, $reference)
    {
        $stockData = $product->values['common'] ?? [];

        $stockSources = [
            'voorraad_eurogros',
            'voorraad_5_korting',
            'voorraad_5_korting_handmatig',
            'voorraad_hw_5_korting',
            'uitverkoop_15_korting',
        ];

        $stock = 0;
        foreach ($stockSources as $source) {
            $stock += (int) ($stockData[$source] ?? 0);
        }

        $data = [
            'amount'            => $stock,
            'managedByRetailer' => true,
        ];

        return $apiClient->put('/retailer/offers/'.$reference.'/stock', $data);
    }

    /**
     * @throws GuzzleException
     */
    protected function updateProductDetails(Product $product, BolApiClient $apiClient, $reference, $deliveryCode)
    {
        $title = $product->values['common']['productnaam'];

        $data = [
            'onHoldByRetailer'    => false,
            'unknownProductTitle' => $title,
            'fulfilment'          => [
                'method'       => 'FBR',
                'deliveryCode' => $deliveryCode,
            ],
        ];

        return $apiClient->put('/retailer/offers/'.$reference, $data);
    }

    protected function deleteProductFromBolCom(Product $product, BolApiClient $apiClient, $reference, BolComCredential $bolComCredential)
    {
        $apiClient->delete('/retailer/offers/'.$reference);

        $product->bolComCredentials()->detach($bolComCredential->id);
    }

    protected function buildProductData(Product $product, $deliveryCode)
    {
        $data = $product->values['common'] ?? [];

        $ean = $data['ean'];
        $title = $data['productnaam'];
        $sku = $product->sku;
        $priceData = $data['prijs'] ?? [];
        $price = isset($priceData['EUR']) ? (float) $priceData['EUR'] : 0;
        $price = (float) number_format($price, 2, '.', '');

        $stockSources = [
            'voorraad_eurogros',
            'voorraad_5_korting',
            'voorraad_5_korting_handmatig',
            'voorraad_hw_5_korting',
            'uitverkoop_15_korting',
        ];

        $stock = 0;
        foreach ($stockSources as $source) {
            $stock += (int) ($data[$source] ?? 0);
        }

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
                        'unitPrice' => $price,
                    ],
                ],
            ],
            'stock' => [
                'amount'            => $stock,
                'managedByRetailer' => true,
            ],
            'fulfilment' => [
                'method'       => 'FBR',
                'deliveryCode' => $deliveryCode,
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
            ->where('is_active', 1)
            ->get();

        $options = [];
        foreach ($credentials as $credential) {
            $options[$credential->id] = $credential->name;
        }

        return $options;
    }
}
