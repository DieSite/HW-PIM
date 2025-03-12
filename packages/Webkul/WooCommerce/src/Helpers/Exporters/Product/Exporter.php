<?php

namespace Webkul\WooCommerce\Helpers\Exporters\Product;

use Illuminate\Support\Facades\Storage;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\DataTransfer\Contracts\JobTrackBatch as JobTrackBatchContract;
use Webkul\DataTransfer\Helpers\Export as ExportHelper;
use Webkul\DataTransfer\Helpers\Export;
use Webkul\DataTransfer\Helpers\Exporters\AbstractExporter;
use Webkul\DataTransfer\Jobs\Export\File\FlatItemBuffer as FileExportFileBuffer;
use Webkul\DataTransfer\Repositories\JobTrackBatchRepository;
use Webkul\WooCommerce\Repositories\AttributeMappingRepository;
use Webkul\WooCommerce\Repositories\CredentialRepository;
use Webkul\WooCommerce\Repositories\DataTransferMappingRepository;
use Webkul\WooCommerce\Services\WooCommerceService;
use Webkul\WooCommerce\Traits\DataTransferMappingTrait;
use Webkul\WooCommerce\Traits\RestApiRequestTrait;

class Exporter extends AbstractExporter
{
    use DataTransferMappingTrait;
    use RestApiRequestTrait;

    public const BATCH_SIZE = 10;

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

    /*
     * For exporting file
     */
    protected bool $exportsFile = false;

    /**
     * Current crenetial.
     *
     * @var array
     */
    protected $credential = [];

    protected $mappingFields = [];

    protected $customAttributes = [];

    protected $channel;

    protected $currency;

    protected $mediaExport = false;

    protected $locale;

    protected $mainAttributes = [
        'sku',
        'name',
    ];

    protected $defaultValues = [];

    protected $mediaMappings = [];

    /**
     * @var array
     */
    protected $attributes = [];

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
    ) {
        parent::__construct($exportBatchRepository, $exportFileBuffer);
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

        return $sku
            ? $this->source->with(['attribute_family', 'parent', 'super_attributes', 'variants'])->whereNull('parent_id')
                ->whereIn('sku', explode(',', $sku))->get()->getIterator()
            : $this->source->with(['attribute_family', 'parent', 'super_attributes', 'variants'])->whereNull('parent_id')->get()->getIterator();
    }

    public function exportProduct(JobTrackBatchContract $batch)
    {
        $id = $this->credential->id;
        $this->mappingFields = $this->connectorService->getAttributeMappings($id);
        $this->defaultValues = $this->connectorService->getDefaultMappings($id);
        $this->customAttributes = $this->connectorService->getCustomAttributes($id);
        $this->mediaMappings = $this->connectorService->getMediaMappings($id);

        foreach ($batch->data as $item) {
            $this->exportModelsAndProducts($item, $id);

            $this->createdItemsCount++;
        }
    }

    protected function exportModelsAndProducts($item, $id)
    {
        $item['code'] = $item['sku'];
        $item['type'] = ! empty($item['variants']) ? 'variable' : 'simple';
        $mapping = $this->getDataTransferMapping($item['code']) ?? null;
        $formattedData = $this->formatData($item);

        if (empty($mapping)) {
            $result = $this->connectorService->requestApiAction(
                self::ACTION_ADD,
                $formattedData,
                ['credential' => $id]
            );
            $reResult = $this->handleAfterApiRequest($item, $result);
        } else {
            $result = $this->connectorService->requestApiAction(
                self::ACTION_UPDATE,
                $formattedData,
                ['credential' => $id, 'id' => $mapping[0]['externalId']]
            );

            $reResult = $this->handleAfterApiRequest($item, $result, null, $mapping);
        }

        $this->exportVariants($item, $reResult, $id);
    }

    protected function exportVariants($item, $reResult, $id)
    {
        $varResult = null;
        if (! empty($reResult) && ! empty($item['variants']) && ! empty($reResult['id']) && ! empty($item['super_attributes'])) {
            foreach ($item['variants'] as $varItem) {
                $formattedVariation = $this->formatVariation($varItem, $item['super_attributes']);
                $mapping = $this->getDataTransferMapping($varItem['sku'], self::UNOPIM_ENTITY_NAME);

                if (! $mapping) {
                    $this->reMappingExistingProductVariations($reResult['id'], $id);

                    $mapping = $this->getDataTransferMapping($varItem['sku'], self::UNOPIM_ENTITY_NAME);
                }

                if ($mapping) {
                    $varResult = $this->connectorService->requestApiAction(
                        self::ACTION_UPDATE_VARIATION,
                        $formattedVariation,
                        [
                            'product'    => $reResult['id'],
                            'id'         => $mapping[0]['externalId'],
                            'credential' => $id,
                        ]
                    );

                    $this->updateDataTransferMappingByCode($varItem['sku'], $mapping[0]);
                }

                if (empty($mapping)) {
                    $varResult = $this->connectorService->requestApiAction(
                        self::ACTION_ADD_VARIATION,
                        $formattedVariation,
                        ['product' => $reResult['id'], 'credential' => $id]
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

    public function formatData($item)
    {
        $formatted = [
            'name'        => $item['code'],
            'slug'        => $item['code'],
            'sku'         => $item['code'],
            'status'      => $item['status'] === 1 ? 'publish' : 'draft',
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
                    $formatted[$this->wrapper[$name]][strtolower($name)] = (string) $duplicateAttributes[$field];
                } else {
                    if ($name == 'regular_price') {
                        $formatted[$name] = $duplicateAttributes[$field][$this->currency];
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

        $formatted['virtual'] = empty($formatted['weight']);

        /* categories */
        $categories = collect($attributes['categories'] ?? [])->reject(fn ($code) => strtolower($code) === 'uncategorized')->map(function ($code) {

            $categoryMapping = $this->getDataTransferMapping($code, self::UNOPIM_CATEGORY_ENTITY);

            return ! empty($categoryMapping[0]['externalId']) ? ['id' => $categoryMapping[0]['externalId']] : null;
        })->filter()->values()->toArray();

        unset($attributes['categories']);

        $formatted['categories'] = $categories;

        /* other attributes */
        $variantAttributesKeys = ! empty($item['super_attributes']) ? array_column($item['super_attributes'], 'code') : [];
        $variantAttris = array_combine($variantAttributesKeys, array_fill(0, count($variantAttributesKeys), ''));

        $attributes = array_merge($attributes, $variantAttris);
        $imageAttributeCodes = $this->getAttributeCodesFromType('image');
        $imagesToExport = array_intersect($this->mediaMappings, $imageAttributeCodes);

        $this->formatAdditionalData($formatted, $attributes, $imagesToExport, $item);

        return $formatted;
    }

    protected function formatAdditionalData(&$formatted, $attributes, $imagesToExport, $item)
    {
        $customAttrs = [];
        $imageAttrs = [];
        $selectAttributeCodes = $this->getAttributeCodesFromType('select');
        $multiSelectVariation = $this->getAttributeCodesFromType('multiselect');
        $booleanVariation = $this->getAttributeCodesFromType('boolean');

        foreach ($attributes as $code => $value) {
            $enableSelect = isset($this->credential['extras']) && isset($this->credential['extras']['enableSelect']) && $this->credential['extras']['enableSelect'] == 1;
            $attribute = $this->attributeRepository->findOneByField('code', $code);

            if ($attribute && $attribute->type == 'boolean') {
                if ($value === true) {
                    $value = 'Yes';
                } else {
                    $value = 'No';
                }
            }

            if ($this->mediaExport && in_array($code, $imagesToExport)) {
                $imageAttrs[] = $this->formatImageData($code, $value, $imagesToExport, $item['code']);
            } else {
                if (! in_array($code, $this->mainAttributes)) {
                    $attributeMapping = $this->getDataTransferMapping($code, self::UNOPIM_ATTRIBUTE_ENTITY);
                    if (isset($this->customAttributes)) {
                        if (! in_array($code, $selectAttributeCodes) && ! in_array($code, $multiSelectVariation) && ! in_array($code, $booleanVariation)) {
                            if (in_array($code, $this->customAttributes)) {
                                $optionValueMapping = $this->getDataTransferMapping($value, self::ATTRIBUTE_OPTION_ENTITY_NAME);

                                if (! $optionValueMapping) {
                                    $this->createOptions($code, $value, $this->locale, self::ATTRIBUTE_OPTION_ENTITY_NAME);
                                }

                                if ($attributeMapping && $optionValueMapping) {
                                    $customAttr = [
                                        'id'        => $attributeMapping[0]['externalId'],
                                        'visible'   => true,
                                        'variation' => false,
                                        'options'   => is_array($value) ? $value : [$value],
                                    ];

                                    $customAttrs[] = $customAttr;
                                }
                            }
                        }
                    }

                    if (! $enableSelect) {
                        if (isset($attributeMapping) && ! empty($attributeMapping)) {
                            if (! in_array($attributeMapping[0]['code'], $selectAttributeCodes) && ! in_array($attributeMapping[0]['code'], $multiSelectVariation) && ! in_array($attributeMapping[0]['code'], $booleanVariation)) {
                                $attributeMapping = [];
                            }
                        }
                    }

                    if (! empty($attributeMapping[0]['externalId'])) {
                        $variantAttributes = ! empty($item['super_attributes']) ? array_column($item['super_attributes'], 'code') : [];
                        $variation = ! empty($variantAttributes) && in_array($code, $variantAttributes);

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
    }

    protected function formatImageData($code, $value, $imagesToExport, $itemCode, $isVariant = false)
    {
        $getKey = array_search($code, $imagesToExport);
        $imageUrl = $this->generateImageUrl($value);

        if (! $imageUrl) {
            return [];
        }

        $id = $this->checkImagesExported($itemCode, $imageUrl);

        if ($isVariant) {
            return $id ? ['id' => $id] : ['src' => $imageUrl, 'name' => $this->getImageName($imageUrl)];
        }

        return $id
            ? ['id' => $id, 'position' => $getKey]
            : ['src' => $imageUrl, 'name' => $this->getImageName($imageUrl), 'position' => $getKey];
    }

    public function formatVariation($item, $superAttributes = [])
    {
        $variantAttributes = ! empty($superAttributes) ? array_column($superAttributes, 'code') : [];
        $attributes = $this->formatAttributes($item['values']);
        $imageAttributeCodes = $this->getAttributeCodesFromType('image');
        $imagesToExport = array_intersect($this->mediaMappings, $imageAttributeCodes);
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
                    if ($varField == 'regular_price') {
                        $formatted[$varField] = $attributes[$fieldAlias][$this->currency];
                    } else {
                        $formatted[$varField] = $attributes[$fieldAlias];
                    }
                    $this->addDependentField($formatted, $varField, $attributes[$fieldAlias]);
                }
            }
        }

        foreach ($this->defaultValues as $name => $value) {
            if (! in_array($name, $variantAttributes)) {
                if ($value !== '') {
                    if (! empty($this->wrapper[$name])) {
                        $formatted[$this->wrapper[$name]][strtolower($name)] = $value;
                    } else {
                        if (in_array($name, $formatted) && $name != 'regular_price') {
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
                $attribute = $this->attributeRepository->findOneByField('code', $varAttribute);
                if ($attribute->type == 'boolean') {
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
            if ($this->mediaExport && in_array($code, $imagesToExport)) {
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
        return $this->attributeRepository->findWhere(['type' => $type])->pluck('code')->toArray();
    }

    protected function generateImageUrl(string $image): string
    {
        return Storage::url(str_replace(' ', '%20', $image));
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

    protected $wrapper = [
        'Length' => 'dimensions',
        'width'  => 'dimensions',
        'height' => 'dimensions',
    ];
}
