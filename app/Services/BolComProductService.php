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

                return null;
            }

            if (! $product->bol_com_credential_id) {
                Log::warning('No Bol.com credential selected for product sync', ['product_id' => $product->id]);

                return null;
            }

            $apiClient = new BolApiClient($product->bol_com_credential_id);

            if (! $previousSyncState || ! $product->bol_com_reference) {
                return $this->createProductOnBolCom($product, $apiClient);
            } else {
                return $this->updateProductOnBolCom($product, $apiClient);
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

        return $apiClient->post('/retailer/offers', $data);

    }

    /**
     * @throws GuzzleException
     */
    /**
     * @throws GuzzleException
     */
    protected function updateProductOnBolCom(Product $product, BolApiClient $apiClient)
    {
        $this->updateProductPrice($product, $apiClient);
        $this->updateProductStock($product, $apiClient);
        $this->updateProductDetails($product, $apiClient);

        return true;
    }

    /**
     * @throws GuzzleException
     */
    protected function updateProductPrice(Product $product, BolApiClient $apiClient)
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

        return $apiClient->put('/retailer/offers/'.$product->bol_com_reference.'/price', $data);
    }

    /**
     * @throws GuzzleException
     */
    protected function updateProductStock(Product $product, BolApiClient $apiClient)
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

        return $apiClient->put('/retailer/offers/'.$product->bol_com_reference.'/stock', $data);
    }

    /**
     * @throws GuzzleException
     */
    protected function updateProductDetails(Product $product, BolApiClient $apiClient)
    {
        $title = $product->values['common']['productnaam'];

        $data = [
            'onHoldByRetailer'    => false,
            'unknownProductTitle' => $title,
            'fulfilment'          => [
                'method'       => 'FBR',
                'deliveryCode' => '1-8d',
            ],
        ];

        return $apiClient->put('/retailer/offers/'.$product->bol_com_reference, $data);
    }

    protected function deleteProductFromBolCom(Product $product, BolApiClient $apiClient)
    {
        $apiClient->delete('/retailer/offers/'.$product->bol_com_reference);

        $product->bol_com_reference = null;
        $product->bol_com_sync = false;
        $product->bol_com_credential_id = null;
        $product->save();
    }

    protected function buildProductData(Product $product)
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
