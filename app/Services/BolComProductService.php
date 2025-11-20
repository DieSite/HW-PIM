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
use Illuminate\Support\Facades\Storage;
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
            $apiClient = new BolApiClient();
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
        $apiClient = new BolApiClient();
        $apiClient->setCredential($bolComCredential);

        return $apiClient->get("/shared/process-status/$id");
    }

    public function fetchUploadReport(string $id, BolComCredential $bolComCredential)
    {
        $apiClient = new BolApiClient();
        $apiClient->setCredential($bolComCredential);

        return $apiClient->get("/retailer/content/upload-report/$id");
    }

    public function fetchCategories(BolComCredential $bolComCredential)
    {
        $apiClient = new BolApiClient();
        $apiClient->setCredential($bolComCredential);

        return $apiClient->get('/retailer/products/categories');
    }

    public function fetchCatalogProductDetails(BolComCredential $bolComCredential, string $ean, bool $assets)
    {
        $apiClient = new BolApiClient();
        $apiClient->setCredential($bolComCredential);

        if ($assets) {
            $endpoint = "/retailer/products/$ean/assets";
        } else {
            $endpoint = "/retailer/content/catalog-products/$ean";
        }

        return $apiClient->get($endpoint);
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

    /**
     * @throws GuzzleException
     */
    /**
     * @throws GuzzleException
     */
    protected function updateProductOnBolCom(Product $product, BolApiClient $apiClient, $reference, $deliveryCode)
    {
        $data = $this->buildContentData($product, true);

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

        $this->updateProductPrice($product, $apiClient, $reference);
        $stockResponse = $this->updateProductStock($product, $apiClient, $reference);
        Log::info('StockResponse', ['response' => $stockResponse]);
        $this->updateProductDetails($product, $apiClient, $reference, $deliveryCode);

        return true;
    }

    /**
     * @throws GuzzleException
     */
    protected function updateProductPrice(Product $product, BolApiClient $apiClient, $reference)
    {
        $price = $this->getProductPrice($product);

        Log::debug('BOL.com price', ['price' => $price]);

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
            'voorraad_5_korting_handmatig',
            'voorraad_hw_5_korting',
        ];

        $stock = 0;
        foreach ($stockSources as $source) {
            Log::info('BOL.com stock', ['source' => $stockData[$source] ?? 'unknown', 'title' => $source]);
            $stock += (int) ($stockData[$source] ?? 0);
        }
        Log::info('BOL.com stock', ['stock' => $stock]);

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

        $price = $this->getProductPrice($product);

        Log::debug('BOL.com price', ['price' => $price]);

        $stockSources = [
            'voorraad_eurogros',
            'voorraad_5_korting_handmatig',
            'voorraad_hw_5_korting',
        ];

        $stock = 0;
        foreach ($stockSources as $source) {
            Log::info('BOL.com stock', ['source' => $data[$source] ?? 'unknown', 'title' => $source]);
            $stock += (int) ($data[$source] ?? 0);
        }

        Log::info('BOL.com stock', ['stock' => $stock]);

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

    protected function buildContentData(Product $product, bool $forUpdate = false): array
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
                    'value'  => '1',
                    'unitId' => 'unece.unit.EA',
                ],
            ],
            [
                'id'     => 'Type of Rug',
                'values' => [
                    'value' => 'Vloerkleed',
                ],
            ],
            [
                'id'     => 'Pile type',
                'values' => [
                    'value' => $pileType,
                ],
            ],
            [
                'id'     => 'GPC Code',
                'values' => [
                    'value' => '14176', // TODO
                ],
            ],
            [
                'id'     => 'Indoor or Outdoor',
                'values' => [
                    'value' => 'Voor binnen',
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
                'id'     => 'Colour',
                'values' => $colors,
            ];

            $attributes[] = [
                'id'     => 'Colour Group',
                'values' => $colors,
            ];
        }

        if (! empty($width)) {
            $attributes[] = [
                'id'     => 'Product Width',
                'values' => [
                    'value'  => $width,
                    'unitId' => 'cm',
                ],
            ];
        }

        if (! empty($length)) {
            $attributes[] = [
                'id'     => 'Product Length',
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

        if (! empty($shape)) {
            $attributes[] = [
                'id'     => 'Shape',
                'values' => [
                    'value' => $shape,
                ],
            ];
        }

        if (!empty($merk)) {
            $attributes[] = [
                'id'     => 'Brand',
                'values' => [
                    'value' => $merk,
                ],
            ];
        }

        $assets = [];

        if (! empty($product->parent->values['common']['afbeelding_zonder_logo'])) {
            $images = explode(',', $product->parent->values['common']['afbeelding_zonder_logo'] ?? '');
        } elseif (! empty($product->parent->values['common']['afbeelding'])) {
            $images = explode(',', $product->parent->values['common']['afbeelding'] ?? '');
        } else {
            $images = [];
        }

        Log::debug('Images', ['images' => $images]);
        foreach ($images as $image) {
            if (empty($image)) {
                continue;
            }

            $label = empty($assets) ? 'PRIMARY' : 'DETAIL';

            $assets[] = [
                'url'    => $this->generateImageUrl($image),
                'labels' => [$label],
            ];
        }

        $data = [
            'language'   => 'nl',
            'attributes' => $attributes,
        ];

        if (! empty($assets)) {
            $data['assets'] = $assets;
        }

        return $data;
    }

    protected function getCredentialsId()
    {
        $credential = DB::table('bol_com_credentials')
            ->where('is_active', 1)
            ->first();

        return $credential?->id;
    }

    protected function generateImageUrl(string $image): string
    {
        $damAsset = \DB::table('dam_assets')->where('id', $image)->first();

        if ($damAsset && ! empty($damAsset->path)) {
            $image = $damAsset->path;
        }

        return Storage::disk('private')->url($image);
    }

    public function getProductPrice(Product $product, bool $excludeOverride = false): float
    {
        $values = $product->values['common'] ?? [];

        if ($product->bol_price_override && !$excludeOverride) {
            $priceData = ['EUR' => $product->bol_price_override];
        } else {
            $priceData = $values['prijs'] ?? 0;

            if (isset($values['merk'])) {
                $snake = \Str::snake($values['merk']);
                $discount = config("bolcom.bol_discounts.$snake", 1);
                $priceData['EUR'] = $priceData['EUR'] * $discount;
            }
        }

        $price = isset($priceData['EUR']) ? (float) $priceData['EUR'] : 0;

        return (float) number_format($price, 2, '.', '');
    }
}
