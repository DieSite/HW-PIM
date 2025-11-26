<?php

namespace Webkul\WooCommerce\Helpers\Exporters\Product;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Sentry\Severity;
use Sentry\State\Scope;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\DataTransfer\Contracts\JobTrackBatch as JobTrackBatchContract;
use Webkul\DataTransfer\Helpers\Export as ExportHelper;
use Webkul\DataTransfer\Helpers\Exporters\AbstractExporter;
use Webkul\DataTransfer\Jobs\Export\File\FlatItemBuffer as FileExportFileBuffer;
use Webkul\DataTransfer\Repositories\JobTrackBatchRepository;
use Webkul\DataTransfer\Services\JobLogger;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\WooCommerce\Repositories\AttributeMappingRepository;
use Webkul\WooCommerce\Repositories\CredentialRepository;
use Webkul\WooCommerce\Repositories\DataTransferMappingRepository;
use Webkul\WooCommerce\Services\WooCommerceService;
use Webkul\WooCommerce\Traits\DataTransferMappingTrait;
use Webkul\WooCommerce\Traits\RestApiRequestTrait;

use function Sentry\captureMessage;

class Exporter extends AbstractExporter
{
    use DataTransferMappingTrait;
    use RestApiRequestTrait;

    public const BATCH_SIZE = 100;

    const VOORRAAD_TAG_ID = 1392;

    const UITVERKOOP_TAG_ID = 1582;

    /**
     * unopim entity name.
     *
     * @var string
     */
    public const ACTION_ADD = 'addProduct';

    public const ACTION_UPDATE = 'updateProduct';

    public const UNOPIM_ENTITY_NAME = 'product';

    public const UNOPIM_ATTRIBUTE_ENTITY = 'attribute';

    public const UNOPIM_CATEGORY_ENTITY = 'category';

    public const ATTRIBUTE_OPTION_ENTITY_NAME = 'option';

    public const CODE_ALREADY_EXIST = 'product_invalid_sku';

    public const TERM_ALREADY_EXIST = 'term_exists';

    public const CODE_DUPLICATE_EXIST = 'duplicate_term_slug';

    public const CODE_NOT_EXIST = 'woocommerce_rest_term_invalid';

    public const CODE_DELETED_VARIATION = 'woocommerce_rest_product_variation_invalid_id';

    public const CODE_INAVLID_IMAGE_ID = 'woocommerce_product_invalid_image_id';

    public const RELATED_INDEX = null;

    public const ACTION_GET = 'getAllProduct';

    public const ACTION_ADD_VARIATION = 'addVariation';

    public const ACTION_UPDATE_VARIATION = 'updateVariation';

    public const DEFAULTS_SECTION = 'woocommerce_connector_defaults';

    public const OTHER_MAPPINGS_SECTION = 'woocommerce_other_mappings';

    public const ACTION_GET_SYSTEM_STATUS = 'getSystemStatus';

    public const WPML_CODE_ALREADY_EXIST = 'Failed! SKU already exists.';

    public const WPML_GET_DATA = 'getWpmlProduct';

    public const WPML_INVALID_CODE = 'Failed! Product id not exists.';

    public const ADD_MEDIA = 'addMedia';

    public const GET_ACTION_ACF_FIELD = 'getACFField';

    public const ACTION_FROM = 'wordpress';

    public $credential;

    public $channel;

    public $currency;

    public $locale;

    /*
     * For exporting file
     */
    protected bool $exportsFile = false;

    protected $mappingFields = [];

    protected $customAttributes = [];

    protected $mediaExport = false;

    protected $mainAttributes = [
        'sku',
        'name',
    ];

    protected $defaultValues = [];

    protected $mediaMappings = [];

    protected $imageAttributeCodes = [];

    protected $selectAttributeCodes = [];

    protected $multiSelectVariation = [];

    protected $booleanVariation = [];

    protected $enableSelect = false;

    protected $id;

    /**
     * @var array
     */
    protected $attributes = [];

    protected $wrapper = [
        'Length'                => 'dimensions',
        'width'                 => 'dimensions',
        'height'                => 'dimensions',
        '_bol_ean'              => 'meta_data',
        '_expected_week'        => 'meta_data',
        'maximale_breedte'      => 'meta_data',
        'maximale_lengte'       => 'meta_data',
        'maximale_diagonaal'    => 'meta_data',
        'prijs_per_m'           => 'meta_data',
        'sale_prijs_per_m'      => 'meta_data',
        'prijs_rond_per_m'      => 'meta_data',
        'sale_prijs_rond_per_m' => 'meta_data',
        '_yoast_wpseo_title'    => 'meta_data',
        '_yoast_wpseo_metadesc' => 'meta_data',
    ];

    /**
     * Create a new instance of the exporter.
     */
    public function __construct(
        protected JobTrackBatchRepository $exportBatchRepository,
        protected FileExportFileBuffer $exportFileBuffer,
        protected DataTransferMappingRepository $dataTransferMappingRepository,
        protected AttributeRepository $attributeRepository,
        protected CredentialRepository $credentialRepository,
        protected WooCommerceService $connectorService,
        protected AttributeMappingRepository $attributeMappingRepository,
        protected ProductRepository $productRepository
    ) {
        parent::__construct($exportBatchRepository, $exportFileBuffer);
        $this->setLogger(JobLogger::make("UpdateExport-$this->id"));
        $this->initAttributes();
    }

    /**
     * Initializes the data for the export process.
     *
     * @return void
     */
    public function initialize()
    {
        $this->initCredential();
        $this->locale = $this->jobFilters['locale'];
        $this->channel = $this->jobFilters['channel'];
        $this->currency = $this->jobFilters['currency'];
        $this->mediaExport = $this->jobFilters['with_media'] == 1 ? true : false;
        $this->id = $this->credential?->id;
    }

    public function setMediaExport(bool $value)
    {
        $this->mediaExport = $value;
    }

    /**
     * Start the export process
     */
    public function exportBatch(JobTrackBatchContract $batch, $filePath): bool
    {
        $this->initialize();

        $this->exportProduct($batch);

        /**
         * Update export batch process state summary
         */
        $this->updateBatchState($batch->id, ExportHelper::STATE_PROCESSED);

        return true;
    }

    public function exportProduct(JobTrackBatchContract $batch)
    {
        $this->initMappingsAndAttribute();
        $allProducts = $this->getProductBatches($batch->data);

        foreach ($allProducts as $item) {
            $this->exportModelsAndProducts($item);

            $this->createdItemsCount++;
        }
    }

    public function initMappingsAndAttribute()
    {
        /* InitializeMapping */
        $extras = $this->credential['extras'] ?? [];
        $this->mappingFields = $extras['settings'] ?? [];
        $this->defaultValues = $extras['defaults'] ?? [];
        $this->customAttributes = $extras['custom_field'] ?? [];
        $this->mediaMappings = $extras['media'] ?? [];
        $this->enableSelect = ($this->credential['extras']['enableSelect'] ?? 0) == 1;

        /* Find attributeCodes using attributetypes */
        $this->imageAttributeCodes = $this->getAttributeCodesFromType('asset');
        $this->selectAttributeCodes = $this->getAttributeCodesFromType('select');
        $this->multiSelectVariation = $this->getAttributeCodesFromType('multiselect');
        $this->booleanVariation = $this->getAttributeCodesFromType('boolean');
    }

    public function formatData($item)
    {
        $formatted = [
            'name'        => $item['code'],
            'slug'        => $item['code'],
            'sku'         => $item['code'],
            'status'      => ! empty($item['status']) ? ($item['status'] === 1 ? 'publish' : 'draft') : 'draft',
            'type'        => $item['type'],
            'description' => ! empty($item['description']) ? $item['description'] : '',
        ];

        $values = $item['values'];

        $attributes = $this->formatAttributes($values);

        $duplicateAttributes = $attributes;

        /* main attributes */
        foreach ($this->mappingFields as $name => $field) {
            if (is_array($duplicateAttributes) && array_key_exists($field, $duplicateAttributes)) {
                if (! empty($this->wrapper[$name])) {
                    $value = is_array($duplicateAttributes[$field]) ? Arr::first($duplicateAttributes[$field]) : $duplicateAttributes[$field];
                    if ($this->wrapper[$name] === 'meta_data') {
                        $formatted[$this->wrapper[$name]][] = ['key' => strtolower($name), 'value' => (string) $value];
                    } else {
                        $formatted[$this->wrapper[$name]][strtolower($name)] = (string) $value;
                    }

                } else {
                    $attribute = $this->attributes[$field] ?? [];
                    if ($attribute['type'] == 'price') {
                        $formatted[$name] = number_format($duplicateAttributes[$field][$this->currency], 2);
                    } else {
                        $formatted[$name] = $duplicateAttributes[$field];
                    }
                    $this->addDependentField($formatted, $name, $duplicateAttributes[$field]);
                }
                unset($attributes[$field]);
            }
        }

        /* default values */
        if (isset($this->defaultValues) && ! empty($this->defaultValues)) {
            foreach ($this->defaultValues as $name => $value) {
                if ($value !== '') {
                    if (! empty($this->wrapper[$name])) {
                        $formatted[$this->wrapper[$name]][strtolower($name)] = $value;
                    } else {
                        $formatted[$name] = $value;
                        $this->addDependentField($formatted, $name, $value);
                    }
                }
            }
        }

        $formatted['virtual'] = false;

        /* categories */
        $categories = collect($attributes['categories'] ?? [])->reject(fn ($code) => strtolower($code) === 'uncategorized')->map(function ($code) {

            $categoryMapping = $this->getDataTransferMapping($code, self::UNOPIM_CATEGORY_ENTITY);

            return ! empty($categoryMapping[0]['externalId']) ? ['id' => $categoryMapping[0]['externalId']] : null;
        })->filter()->values()->toArray();

        unset($attributes['categories']);

        $formatted['categories'] = $categories;

        $imagesToExport = array_intersect($this->mediaMappings, $this->imageAttributeCodes);

        Log::debug('1 FORMATTED', ['formatted' => $formatted]);
        Log::debug('1 ATTRIBUTES', ['attributes' => $attributes]);

        $this->formatAdditionalData($formatted, $attributes, $imagesToExport, $item);

        $voorraadEurogros = $item['values']['common']['voorraad_eurogros'] ?? 0;
        $voorraadDeMunk = $item['values']['common']['voorraad_5_korting_handmatig'] ?? 0;
        $voorraadHW = $item['values']['common']['voorraad_hw_5_korting'] ?? 0;
        $uitverkoop = $item['values']['common']['uitverkoop_15_korting'] ?? 0;

        $formatted['manage_stock'] = true;
        $formatted['stock_quantity'] = $voorraadEurogros + $voorraadDeMunk + $voorraadHW;
        $formatted['stock_status'] = $formatted['stock_quantity'] > 0 ? 'instock' : 'outofstock';
        $formatted['backorders'] = 'yes';

        if (isset($item['parent_id'])) {
            $formatted['parent_id'] = $item['parent_id'];
            $afhaalkorting = (int) core()->getConfigData('general.discounts.settings.afhaalkorting');
            $regularPrice = $formatted['regular_price'];
            $regularPrice = str_replace(',', '', $regularPrice);
            $regularPrice = (float) $regularPrice;
            $discounted = $regularPrice / 100 * (100 - $afhaalkorting);
            $discounted = round($discounted, 2);
            Log::debug('Afhaalkorting', ['afhaalkorting' => $afhaalkorting, 'regularPrice' => $regularPrice, 'original' => $formatted['regular_price']]);
            $meta = $formatted['meta_data'] ?? [];
            $meta[] = ['key' => 'is_hw_voorraad', 'value' => $uitverkoop > 0 ? 'yes' : 'no'];
            $meta[] = ['key' => 'afhaalkorting_price', 'value' => $discounted];
            $formatted['meta_data'] = $meta;
        } else {
            $onderkleed = collect();
            $maat = collect();
            $maatgroep = collect();
            $festoneren_banderen = collect();
            foreach (Arr::get($item, 'variants', []) as $variant) {
                $onderkleed->push(...Arr::wrap(Arr::get($variant, 'values.common.onderkleed', [])));
                $maat->push(...Arr::wrap(Arr::get($variant, 'values.common.maat', [])));
                $maatgroep->push(...Arr::wrap(Arr::get($variant, 'values.common.maatgroep', [])));
                $festoneren_banderen->push(...Arr::wrap(Arr::get($variant, 'values.common.festoneren_banderen', [])));
            }

            $attributeMappingMaat = $this->getDataTransferMapping('maat', self::UNOPIM_ATTRIBUTE_ENTITY);
            $formatted['attributes'][] = [
                'id'        => $attributeMappingMaat[0]['externalId'],
                'visible'   => true,
                'variation' => true,
                'options'   => $maat->unique()->toArray(),
            ];

            $attributeMappingOnderkleed = $this->getDataTransferMapping('onderkleed', self::UNOPIM_ATTRIBUTE_ENTITY);
            $formatted['attributes'][] = [
                'id'        => $attributeMappingOnderkleed[0]['externalId'],
                'visible'   => true,
                'variation' => true,
                'options'   => $onderkleed->unique()->sortDesc(SORT_NATURAL)->toArray(),
            ];

            $attributeMappingMaatgroep = $this->getDataTransferMapping('maatgroep', self::UNOPIM_ATTRIBUTE_ENTITY);
            $formatted['attributes'][] = [
                'id'        => $attributeMappingMaatgroep[0]['externalId'],
                'visible'   => true,
                'variation' => false,
                'options'   => $maatgroep->unique()->toArray(),
            ];

            $attributeMappingFestonerenBanderen = $this->getDataTransferMapping('festoneren_banderen', self::UNOPIM_ATTRIBUTE_ENTITY);
            $formatted['attributes'][] = [
                'id'        => $attributeMappingMaatgroep[0]['externalId'],
                'visible'   => true,
                'variation' => false,
                'options'   => $festoneren_banderen->unique()->toArray(),
            ];

            $maat = $maat->sort(function ($a, $b) {
                // Controleer eerst of een van beide waarden numeriek is
                $aIsNumeric = is_numeric($a[0]);
                $bIsNumeric = is_numeric($b[0]);

                // Als een waarde numeriek is en de andere niet,
                // dan komt de niet-numerieke waarde eerst
                if ($aIsNumeric && ! $bIsNumeric) {
                    return 1;
                }
                if (! $aIsNumeric && $bIsNumeric) {
                    return -1;
                }

                // Als beide waarden van hetzelfde type zijn (beide numeriek of beide niet-numeriek)
                // dan gebruiken we een normale stringvergelijking
                return strcasecmp($a, $b);
            });

            $festoneren_banderen = $festoneren_banderen->sort();

            $formatted['default_attributes'] = [
                ['id' => $attributeMappingMaat[0]['externalId'], 'option' => $maat->first()],
                ['id' => $attributeMappingOnderkleed[0]['externalId'], 'option' => $onderkleed->first()],
            ];
            if (! empty($attributeMappingFestonerenBanderen)) {
                $formatted['default_attributes'][] = ['id' => $attributeMappingFestonerenBanderen[0]['externalId'], 'option' => $festoneren_banderen->first()];
            }

            $tags = $this->getTags($item);
            if (! is_null($tags)) {
                $formatted['tags'] = $tags;
            }

            $formatted['upsell_ids'] = $this->getUpsellProducts($item);

            $meta = $formatted['meta_data'] ?? [];
            if (! empty($item['values']['common']['afbeelding_zonder_logo'])) {
                $meta[] = ['key' => 'afbeelding_zonder_logo', 'value' => $this->generateImageUrl($item['values']['common']['afbeelding_zonder_logo'])];
            } else {
                $meta[] = ['key' => 'afbeelding_zonder_logo', 'value' => ''];
            }
            $formatted['meta_data'] = $meta;

            $formatted['menu_order'] = Arr::get($item, 'values.common.sorteer_volgorde', 0);
        }

        $foundMerk = false;
        foreach ($formatted['attributes'] as $attribute) {
            if ($attribute['id'] == '1') {
                $foundMerk = true;
                break;
            }
        }

        foreach ($formatted['attributes'] as &$attribute) {
            if ( !isset($attribute['options']) ) {
                continue;
            }
            foreach ($attribute['options'] as &$option) {
                $option = (string) $option;
            }
        }

        Log::debug('Formatted', ['formatted' => $formatted]);

        if (! $foundMerk) {
            \Sentry::configureScope(function (Scope $scope) use ($formatted) {
                $scope->setContext('formatted', $formatted);
            });
            captureMessage('Merk niet gevonden in formatted data', Severity::warning());
        }

        if (! isset($formatted['parent_id']) // Is parent product and has no images
            && (! isset($formatted['images'])
                || ! is_array($formatted['images'])
                || count($formatted['images']) < 1)) {
            \Sentry::configureScope(function (Scope $scope) use ($formatted, $item, $imagesToExport) {
                $scope->setContext('formatted', $formatted);
                $scope->setContext('item', $item);
                $scope->setContext('imagesToExport', ['imagesToExport' => $imagesToExport]);
                $scope->setContext('mediaMappings', ['mediaMappings' => $this->mediaMappings]);
                $scope->setContext('imageAttributeCodes', ['imageAttributeCodes' => $this->imageAttributeCodes]);
            });

            throw new \Exception('Het lijkt er op dat er voor dit product geen afbeeldingen zijn opgeslagen. Probeer het opnieuw.');
        }

        return $formatted;
    }

    public function formatVariation($item, $superAttributes = [])
    {
        $variantAttributes = ! empty($superAttributes) ? array_column($superAttributes, 'code') : [];
        $attributes = $this->formatAttributes($item['values']);

        $imagesToExport = array_intersect($this->mediaMappings, $this->imageAttributeCodes);

        $formatted = [
            'attributes' => [],
        ];
        if (isset($attributes['sku'])) {
            $formatted['sku'] = $attributes['sku'];
        }

        /* add possible variant fields */
        foreach (['regular_price', 'stock_quantity', 'weight', 'Length', 'width', 'height', 'backorders_allowed', 'description'] as $varField) {
            $fieldAlias = ! empty($this->mappingFields[$varField]) ? $this->mappingFields[$varField] : null;

            if ($fieldAlias && isset($attributes[$fieldAlias])) {
                if (! empty($this->wrapper[$varField])) {
                    $formatted[$this->wrapper[$varField]][strtolower($varField)] = (string) $attributes[$fieldAlias];
                } else {
                    $attribute = $this->attributes[$fieldAlias] ?? [];
                    if ($attribute['type'] == 'price') {
                        $formatted[$varField] = $attributes[$fieldAlias][$this->currency];
                    } else {
                        $formatted[$varField] = $attributes[$fieldAlias];
                    }
                    $this->addDependentField($formatted, $varField, $attributes[$fieldAlias]);
                }
            }
        }

        $priceArray = ['regular_price', 'sale_price', 'price'];

        foreach ($this->defaultValues as $name => $value) {
            if (! in_array($name, $variantAttributes)) {
                if ($value !== '') {
                    if (! empty($this->wrapper[$name])) {
                        $formatted[$this->wrapper[$name]][strtolower($name)] = $value;
                    } else {
                        if (in_array($name, $formatted) && ! in_array($name, $priceArray)) {
                            continue;
                        }
                        $formatted[$name] = $value;
                        $this->addDependentField($formatted, $name, $value);
                    }
                }
            }
        }

        foreach ($variantAttributes as $varAttribute) {
            if (is_array($attributes) && array_key_exists($varAttribute, $attributes)) {
                $attributeMapping = $this->getDataTransferMapping($varAttribute, self::UNOPIM_ATTRIBUTE_ENTITY);
                $attribute = $this->attributes[$varAttribute] ?? [];

                if ($attribute && $attribute['type'] == 'boolean') {
                    if ($attributes[$varAttribute] === true) {
                        $attributes[$varAttribute] = 'Yes';
                    } else {
                        $attributes[$varAttribute] = 'No';
                    }
                }

                if (! empty($attributeMapping[0]['externalId']) && ! empty($attributes[$varAttribute])) {
                    $optionValue = $attributes[$varAttribute];
                    $formatted['attributes'][] = [
                        'id'     => $attributeMapping[0]['externalId'],
                        'option' => (string) $optionValue,
                    ];
                }
            }
        }

        foreach ($attributes as $code => $value) {
            if ($this->mediaExport && in_array($code, $imagesToExport) && ! is_array($value)) {
                $image = $this->formatImageData($code, $value, $imagesToExport, $item['sku'], true);

                if ($image) {
                    $formatted['image'] = $image;
                    break;
                }
            }
        }

        /* to get items out of trash */
        $formatted['visible'] = $item['status'] === 1 ? true : false;

        /* get enable or disable variant */
        $formatted['status'] = $item['status'] === 1 ? 'publish' : 'private';

        return $formatted;
    }

    public function checkImagesExported($code, $imageUrl)
    {
        $imageName = $this->getImageName($imageUrl);
        $code = $code.'-'.$imageName;

        $imageMapping = $this->getDataTransferMapping($code, 'image');

        if (! $imageMapping) {
            return false;
        }

        return $imageMapping[0]['externalId'];
    }

    public function getImageName($imageurl)
    {
        $getCode = explode('/', $imageurl);
        $getName = end($getCode);

        if ($getName) {
            return $getName;
        }

        return null;
    }

    /**
     * Load all attributes to use later
     */
    protected function initAttributes(): void
    {
        $this->attributes = collect($this->attributeRepository->get(['code', 'type']))->keyBy('code')->toArray();
    }

    /**
     * {@inheritdoc}
     */

    /**
     * {@inheritdoc}
     */
    protected function getResults()
    {
        $filters = $this->getFilters();

        $sku = $filters['productSKU'] ?? null;

        $query = $this->source->whereNull('parent_id');

        if (! empty($sku)) {
            $query->whereIn('sku', explode(',', $sku));
        }

        return $query->get('sku')->getIterator();
    }

    protected function getProductBatches($batchData)
    {
        $allProducts = [];
        $skus = array_column($batchData, 'sku');

        $allProducts = $this->productRepository
            ->with(['attribute_family', 'parent', 'super_attributes', 'variants'])
            ->whereIn('sku', $skus)
            ->get()
            ->toArray();

        return $allProducts;
    }

    protected function exportModelsAndProducts($item)
    {
        $item['code'] = $item['sku'];
        $item['type'] = ! empty($item['variants']) ? 'variable' : 'simple';
        $mapping = $this->getDataTransferMapping($item['code']) ?? null;
        $formattedData = $this->formatData($item);

        if (empty($mapping)) {
            $result = $this->connectorService->requestApiAction(
                self::ACTION_ADD,
                $formattedData,
                ['credential' => $this->id]
            );
            $reResult = $this->handleAfterApiRequest($item, $result);
        } else {
            $result = $this->connectorService->requestApiAction(
                self::ACTION_UPDATE,
                $formattedData,
                ['credential' => $this->id, 'id' => $mapping[0]['externalId']]
            );

            $reResult = $this->handleAfterApiRequest($item, $result, null, $mapping);
        }

        $this->exportVariants($item, $reResult);
    }

    protected function exportVariants($item, $reResult)
    {
        $varResult = null;
        if (! empty($reResult) && ! empty($item['variants']) && ! empty($reResult['id']) && ! empty($item['super_attributes'])) {
            foreach ($item['variants'] as $varItem) {
                $formattedVariation = $this->formatVariation($varItem, $item['super_attributes']);
                $mapping = $this->getDataTransferMapping($varItem['sku'], self::UNOPIM_ENTITY_NAME);

                if (! $mapping) {
                    $this->reMappingExistingProductVariations($reResult['id'], $this->id);

                    $mapping = $this->getDataTransferMapping($varItem['sku'], self::UNOPIM_ENTITY_NAME);
                }

                if ($mapping) {
                    $varResult = $this->connectorService->requestApiAction(
                        self::ACTION_UPDATE_VARIATION,
                        $formattedVariation,
                        [
                            'product'    => $reResult['id'],
                            'id'         => $mapping[0]['externalId'],
                            'credential' => $this->id,
                        ]
                    );

                    $this->updateDataTransferMappingByCode($varItem['sku'], $mapping[0]);
                }

                if (empty($mapping)) {
                    $varResult = $this->connectorService->requestApiAction(
                        self::ACTION_ADD_VARIATION,
                        $formattedVariation,
                        ['product' => $reResult['id'], 'credential' => $this->id]
                    );

                    $this->createDataTransferMapping($varItem['sku'], $varResult['id'], $reResult['id'], self::UNOPIM_ENTITY_NAME);
                }

                $this->createVariantImageMapping($varItem['sku'], $varResult);
            }
        }
    }

    protected function createVariantImageMapping($itemCode, $result)
    {
        $image = ! empty($result['image']) ? $result['image'] : null;

        if ($image) {
            $imageId = ! empty($image['id']) ? $image['id'] : null;
            $name = $image['yt'] ?? $image['name'];

            if ($imageId) {
                $code = $itemCode.'-'.$name;
                $imageMapping = $this->getDataTransferMapping($code, 'image');

                if ($imageMapping) {
                    $this->updateDataTransferMappingByCode($code, $imageMapping[0]);
                } else {
                    $this->createDataTransferMapping($code, $imageId, null, 'image');
                }
            }
        }
    }

    protected function reMappingExistingProductVariations($productId, $credentialId)
    {
        $exisitingProductVariations = $this->connectorService->requestApiAction(
            'getVariation',
            null,
            ['product' => $productId, 'credential' => $credentialId]
        );

        if (isset($exisitingProductVariations['code']) && $exisitingProductVariations['code'] === 200) {
            foreach ($exisitingProductVariations as $productVariation) {
                if (! isset($productVariation['id'])) {
                    continue;
                }

                $externalId = $productVariation['id'];
                $identifier = $productVariation['sku'];

                $mapping = $this->getDataTransferMapping($identifier, self::UNOPIM_ENTITY_NAME);

                if ($mapping) {
                    $this->updateDataTransferMappingByCode($identifier, $mapping[0]);
                } else {
                    $this->createDataTransferMapping($identifier, $externalId);
                }
            }
        }
    }

    protected function formatAdditionalData(&$formatted, $attributes, $imagesToExport, $item)
    {
        $customAttrs = [];
        $imageAttrs = [];
        $priceArray = ['regular_price', 'sale_price', 'price'];

        Log::debug('Formatted START', $formatted);
        Log::debug('Formatted Attributes', $attributes);

        foreach ($attributes as $code => $value) {
            $attribute = $this->attributes[$code] ?? [];

            if ($attribute && $attribute['type'] == 'price' && ! in_array($code, $priceArray)) {
                $value = $value[$this->currency];
            }

            if (is_null($value)) {
                continue;
            }

            if ($attribute && $attribute['type'] == 'boolean') {
                if ($value === true) {
                    $value = 'Yes';
                } else {
                    $value = 'No';
                }
            }

            if (in_array($code, $imagesToExport) && is_array($value)) {
                $value = implode(',', $value);
            }

            if ($this->mediaExport && in_array($code, $imagesToExport) && ! is_array($value)) {
                $formattedImageData = $this->formatImageData($code, $value, $imagesToExport, $item['code']);
                foreach ($formattedImageData as $image) {
                    $imageAttrs[] = $image;
                }
            } else {
                if (! in_array($code, $this->mainAttributes)) {
                    $attributeMapping = $this->getDataTransferMapping($code, self::UNOPIM_ATTRIBUTE_ENTITY);
                    if (isset($this->customAttributes)) {
                        if (! in_array($code, $this->selectAttributeCodes) && ! in_array($code, $this->multiSelectVariation) && ! in_array($code, $this->booleanVariation)) {
                            if (in_array($code, $this->customAttributes)) {

                                if (isset($item['parent_id']) && in_array($code, ['maat', 'onderkleed'])) {
                                    $customAttrs[] = [
                                        'id'     => $attributeMapping[0]['externalId'],
                                        'option' => $value,
                                    ];

                                    continue;
                                }

                                Log::debug('Value: {value} with attribute {attribute}', ['code' => $code, 'value' => $value, 'attribute' => $attribute]);
                                $optionValueMapping = $this->getDataTransferMapping($value, self::ATTRIBUTE_OPTION_ENTITY_NAME);

                                if (! $optionValueMapping) {
                                    $this->createOptions($code, $value, $this->locale, self::ATTRIBUTE_OPTION_ENTITY_NAME);
                                    $optionValueMapping = $this->getDataTransferMapping($value, self::ATTRIBUTE_OPTION_ENTITY_NAME);
                                }

                                if ($attributeMapping && $optionValueMapping) {
                                    if ($code === 'kleuren' || $code === 'materiaal' || $code === 'merk') {
                                        if (str_contains($value, '|')) {
                                            $value = explode('|', $value);
                                        } else {
                                            $value = explode(', ', $value);
                                        }
                                    }
                                    $customAttr = [
                                        'id'        => $attributeMapping[0]['externalId'],
                                        'visible'   => true,
                                        'variation' => false,
                                        'options'   => is_array($value) ? $value : [$value],
                                    ];

                                    $customAttrs[] = $customAttr;

                                    continue;
                                }
                            }
                        }
                    }

                    if (! $this->enableSelect) {
                        if (isset($attributeMapping) && ! empty($attributeMapping)) {
                            if (! in_array($attributeMapping[0]['code'], $this->selectAttributeCodes) && ! in_array($attributeMapping[0]['code'], $this->multiSelectVariation) && ! in_array($attributeMapping[0]['code'], $this->booleanVariation)) {
                                $attributeMapping = [];
                            }
                        }
                    }

                    if (! empty($attributeMapping[0]['externalId'])) {
                        $variantAttributes = []; // Super attributes disabled
                        $variation = ! empty($variantAttributes) && in_array($code, $variantAttributes);

                        if (isset($item['parent_id']) && in_array($code, ['maat', 'onderkleed'])) {
                            $customAttrs[] = [
                                'id'     => $attributeMapping[0]['externalId'],
                                'option' => $value,
                            ];

                            continue;
                        }

                        $customAttr = [
                            'id'        => $attributeMapping[0]['externalId'],
                            'visible'   => true,
                            'variation' => $variation,
                        ];

                        if ($variation) {
                            $options = $this->connectorService->getOptionsByAttribute($code, $this->locale);

                            $customAttr['options'] = $options;
                            $customAttr['visible'] = false;
                        } else {
                            if (in_array($code, $this->customAttributes)) {
                                $customAttr['options'] = is_array($value) ? $value : [$value];
                            }
                        }

                        $customAttrs[] = $customAttr;
                    }
                }
            }
        }

        $formatted['attributes'] = $customAttrs;

        if ($imageAttrs) {
            $formatted['images'] = $imageAttrs;
        }

        Log::debug('Formatted END', ['formatted' => $formatted]);
    }

    protected function formatImageData($code, $value, $imagesToExport, $itemCode, $isVariant = false)
    {
        $getKey = array_search($code, $imagesToExport);

        $imageValues = str_contains($value, ',') ? explode(',', $value) : [$value];

        $images = [];
        foreach ($imageValues as $value) {
            $imageUrl = $this->generateImageUrl($value);

            if (! $imageUrl) {
                continue;
            }

            $imageId = $this->checkImagesExported($itemCode, $imageUrl);

            if ($isVariant) {
                $images[] = $imageId ? ['id' => $imageId] : ['src' => $imageUrl, 'name' => $this->getImageName($imageUrl)];
            }

            $images[] = $imageId
                ? ['id' => $imageId, 'position' => $getKey]
                : ['src' => $imageUrl, 'name' => $this->getImageName($imageUrl), 'position' => $getKey];
        }

        return $images;
    }

    protected function addDependentField(&$formatted, $key, $value)
    {
        switch ($key) {
            case 'stock_quantity':
                $formatted['stock_quantity'] = (int) $formatted['stock_quantity'];
                $formatted['manage_stock'] = true;
                $formatted['in_stock'] = (bool) $value;
                break;
            case 'reviews_allowed':
            case 'featured':
                $formatted[$key] = (bool) $value;
                break;
            case 'backorders_allowed':
                $formatted['backorders'] = ((bool) $formatted['backorders_allowed']) ? 'yes' : 'no';
                unset($formatted['backorders_allowed']);
                break;
        }
    }

    protected function formatAttributes($attributes)
    {
        $attributeData = [];
        $sections = ['common', 'locale_specific', 'channel_specific', 'channel_locale_specific'];
        foreach ($attributes as $fieldType => $fieldData) {
            if (! in_array($fieldType, $sections)) {
                $attributeData[$fieldType] = $fieldData;

                continue;
            }

            switch ($fieldType) {
                case 'locale_specific':
                    $attributeData += $fieldData[$this->locale] ?? [];
                    break;

                case 'channel_specific':
                    $attributeData += $fieldData[$this->channel] ?? [];
                    break;

                case 'channel_locale_specific':
                    $attributeData += $fieldData[$this->channel][$this->locale] ?? [];
                    break;

                default:
                    $attributeData += $fieldData;
            }
        }

        return $attributeData;
    }

    protected function getAttributeCodesFromType($type)
    {
        return collect($this->attributes)->where('type', $type)->pluck('code')->toArray();
    }

    protected function generateImageUrl(string $image): string
    {
        $damAsset = \DB::table('dam_assets')->where('id', $image)->first();

        if ($damAsset && ! empty($damAsset->path)) {
            $image = $damAsset->path;
        }

        return Storage::disk('private')->url($image);
    }

    private function getUpsellProducts(array $item): array
    {
        $crossSellProducts = [];

        $connector = app(WooCommerceService::class);

        foreach (Arr::get($item, 'values.associations.cross_sells', []) as $crossSellSku) {
            if ($crossSellSku === $item['sku']) {
                continue;
            }

            $crossSellItems = $connector->requestApiAction(
                'getProductWithSku',
                [],
                ['sku' => $crossSellSku]
            );

            $ids = collect($crossSellItems)->pluck('id')->reject(fn ($value) => is_null($value))->toArray();

            $crossSellProducts = array_merge($crossSellProducts, $ids);
        }

        return $crossSellProducts;
    }

    private function getTags(array $item): ?array
    {
        $isUitverkoop = false;
        $isVoorraadkorting = false;

        foreach (Arr::get($item, 'variants', []) as $variant) {
            $voorraadEurogros = $variant['values']['common']['voorraad_eurogros'] ?? 0;
            $voorraadDeMunk = $variant['values']['common']['voorraad_5_korting_handmatig'] ?? 0;
            $voorraadHW = $variant['values']['common']['voorraad_hw_5_korting'] ?? 0;

            $stock = $voorraadEurogros + $voorraadDeMunk + $voorraadHW;

            if ($stock > 0) {
                $isVoorraadkorting = true;

                if (isset($variant['values']['common']['uitverkoop_15_korting']) && $variant['values']['common']['uitverkoop_15_korting']) {
                    $isUitverkoop = true;
                    break;
                }
            }
        }

        if ($isUitverkoop && $isVoorraadkorting) {
            return [['id' => self::UITVERKOOP_TAG_ID], ['id' => self::VOORRAAD_TAG_ID]];
        } elseif ($isUitverkoop) {
            return [['id' => self::UITVERKOOP_TAG_ID]];
        } elseif ($isVoorraadkorting) {
            return [['id' => self::VOORRAAD_TAG_ID]];
        } else {
            return [];
        }
    }
}
