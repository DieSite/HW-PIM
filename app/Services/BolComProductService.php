<?php

namespace App\Services;

use App\Clients\BolApiClient;
use App\Mail\BolComSyncSuccess;
use App\Models\BolComCredential;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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

            throw new Exception('Failed to sync with Bol.com', previous: $e);
        }
    }

    public function fetchContentStatus(string $id, BolComCredential $bolComCredential)
    {
        $apiClient = new BolApiClient;
        $apiClient->setCredential($bolComCredential);

        return $apiClient->get("/shared/process-status/$id");
    }

    public function fetchUploadReport(string $id, BolComCredential $bolComCredential)
    {
        $apiClient = new BolApiClient;
        $apiClient->setCredential($bolComCredential);

        return $apiClient->get("/retailer/content/upload-report/$id");
    }

    public function fetchCategories(BolComCredential $bolComCredential)
    {
        $apiClient = new BolApiClient;
        $apiClient->setCredential($bolComCredential);

        return $apiClient->get('/retailer/products/categories');
    }

    /**
     * @throws GuzzleException
     */
    public function createProductOnBolCom(Product $product, BolApiClient $apiClient, $deliveryCode)
    {
        $data = $this->buildContentData($product);

        Log::debug('BOL.com content data', $data);
        try {
            $response = $apiClient->post('/retailer/content/products', $data);
        } catch (\Exception $exception) {
            $previous = $exception->getPrevious();

            if ($previous instanceof \GuzzleHttp\Exception\ClientException) {
                Log::debug('BOL.com response', ['content' => $previous->getResponse()->getBody()->getContents()]);
            }

            throw $exception;
        }
        Log::debug('BOL.com response', $response);

        $data = $this->buildProductData($product, $deliveryCode);

        $offerResponse = $apiClient->post('/retailer/offers', $data);

        Log::debug('BOL.com offer response', $offerResponse);

        return $response;
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
        ];

        $stock = 0;
        foreach ($stockSources as $source) {
            $stock += (int) ($data[$source] ?? 0);
        }

        return [
            'ean'       => $ean,
            'condition' => [
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

    protected function buildContentData(Product $product): array
    {
        $values = $product->values['common'] ?? [];

        $ean = $values['ean'];
        $title = $values['productnaam'];
        $description = $product->parent->values['common']['beschrijving_l'] ?? '';

        $colors = $product->parent->values['common']['kleuren'] ?? '';
        if (str_contains($colors, '|')) {
            $colors = explode('|', $colors);
        } else {
            $colors = explode(', ', $colors);
        }
        $colors = collect($colors)->map(fn ($color) => ['value' => $color])->toArray();

        $maat = $product->values['common']['maat'] ?? '';
        [$width, $length] = explode('x', $maat);
        $width = preg_replace('/\D/', '', $width);
        $length = preg_replace('/\D/', '', $length);

        $material = $product->parent->values['common']['materiaal'] ?? '';
        if (str_contains($material, '|')) {
            $material = explode('|', $material);
        } else {
            $material = explode(', ', $material);
        }
        $material = collect($material)->map(fn ($mat) => ['value' => $mat])->toArray();

        Log::debug('BOL.com product material', ['material' => $material]);

        $merk = $product->parent->values['common']['merk'] ?? '';

        $pileType = $product->parent->values['common']['poolhoogte'] ?? '';
        $pileType = (int) preg_replace('/\D/', '', $pileType);

        if ($pileType > 15) {
            $pileType = 'Hoogpolig';
        } else {
            $pileType = 'Laagpolig';
        }

        $shape = match (strtolower($product->parent->values['common']['vorm'])) {
            'oval'      => 'Ovaal',
            'rechthoek' => 'Rechthoek',
            'rond'      => 'Rond',
            default     => 'Overig'
        };

        if ($shape === 'Rechthoek' && $width === $length) {
            $shape = 'Vierkant';
        }

        $attributes = [
            [
                'id'     => 'Number of Items in Pack',
                'values' => [
                    'value' => '1',
                ],
            ],
            [
                'id'     => 'Type of Rug',
                'values' => [
                    'value' => 'Vloerkleed',
                ],
            ],
            [
                'id'     => 'Pile Type',
                'values' => [
                    'value' => $pileType,
                ],
            ],
            [
                'id'     => 'Category',
                'values' => [
                    'value' => '14176', // TODO
                ],
            ],
        ];

        if (! empty($ean)) {
            $attributes[] = [
                'id'     => 'EAN',
                'values' => [
                    'value' => $ean,
                ],
            ];
        }

        if (! empty($description)) {
            $attributes[] = [
                'id'     => 'Description',
                'values' => [
                    'value' => $description,
                ],
            ];
        }

        if (! empty($title)) {
            $attributes[] = [
                'id'     => 'Name',
                'values' => [
                    'value' => $title,
                ],
            ];
        }

        if (! empty($colors)) {
            $attributes[] = [
                'id'     => 'Color',
                'values' => $colors,
            ];
        }

        if (! empty($width)) {
            $attributes[] = [
                'id'     => 'Width',
                'values' => [
                    'value'  => $width,
                    'unitId' => 'cm',
                ],
            ];
        }

        if (! empty($length)) {
            $attributes[] = [
                'id'     => 'Length',
                'values' => [
                    'value'  => $length,
                    'unitId' => 'cm',
                ],
            ];
        }

        if (! empty($material)) {
            $attributes[] = [
                'id'     => 'Material',
                'values' => $material,
            ];
        }

        if (! empty($merk)) {
            $attributes[] = [
                'id'     => 'Brand',
                'values' => [
                    'value' => $merk,
                ],
            ];
        }

        if (! empty($shape)) {
            $attributes[] = [
                'id'     => 'Rug Shape',
                'values' => [
                    'value' => $shape,
                ],
            ];
        }

        return [
            'language'   => 'nl',
            'attributes' => $attributes,
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

    public function sendSuccessMail(product $product, array $offer, bolComCredential $bolComCredential)
    {
        $recipients = config('bolcom.email_recipients', []);

        if (empty($recipients)) {
            return;
        }

        Mail::to($recipients)->send(new BolComSyncSuccess($product, $offer, $bolComCredential));
    }
}
