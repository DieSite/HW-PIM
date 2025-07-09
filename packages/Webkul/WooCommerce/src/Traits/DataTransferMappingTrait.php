<?php

namespace Webkul\WooCommerce\Traits;

use Illuminate\Http\JsonResponse;
use Webkul\DataTransfer\Helpers\Export as ExportHelper;
use Webkul\WooCommerce\Exceptions\InvalidCredential;

/**
 * data mapping
 */
trait DataTransferMappingTrait
{
    protected $jobFilters;

    public const STORE_URL_FILTER = 'credential';

    /**
     * Initialize credentials data from filters
     */
    protected function initCredential(): void
    {
        $this->jobFilters = $this->getFilters();

        $this->credential = $this->credentialRepository->find($this->jobFilters[self::STORE_URL_FILTER]);

        if (! is_array($this->credential) || ! $this->credential['active']) {
            $this->jobLogger->warning(trans('woocommerce::app.data-transfer.exports.error.invalid'));

            $this->export->state = ExportHelper::STATE_FAILED;
            $this->export->errors = [trans('woocommerce::app.data-transfer.exports.error.invalid')];
            $this->export->save();

            throw new InvalidCredential;
        }
    }

    /**
     * Check if the mapping exists in the database for the given item.
     */
    protected function getDataTransferMapping(string $code, ?string $entityName = null): ?array
    {
        if ($code) {
            $mappingCheck = $this->dataTransferMappingRepository->where('code', $code)
                ->where('entityType', $entityName ?? self::UNOPIM_ENTITY_NAME)
                ->where('apiUrl', $this?->credential?->shopUrl ?? $this->credential['shopUrl'])
                ->get();

            return $mappingCheck?->toArray();
        }

        return null;
    }

    protected function getMappingByExternalId(string $externalId, ?string $entityName = null): ?array
    {
        if ($externalId) {
            $mappingCheck = $this->dataTransferMappingRepository->where('externalId', $externalId)
                ->where('entityType', $entityName ?? self::UNOPIM_ENTITY_NAME)
                ->where('apiUrl', $this?->credential?->shopUrl ?? $this->credential['shopUrl'])
                ->first();

            return $mappingCheck?->toArray();
        }

        return null;
    }

    protected function createDataTransferMapping($code, $externalId, $relatedId = null, $entityName = null)
    {
        if (! isset($this->export)) {
            return;
        }
        $mappingData = [
            'entityType'    => $entityName ?? self::UNOPIM_ENTITY_NAME,
            'code'          => $code,
            'externalId'    => $externalId,
            'relatedId'     => $relatedId ?? null,
            'jobInstanceId' => $this->export?->id ?? null,
            'apiUrl'        => is_array($this->credential) ? $this->credential['shopUrl'] : null,
        ];

        $this->dataTransferMappingRepository->create($mappingData);
    }

    protected function updateDataTransferMappingByCode(string $code, array $data)
    {
        $this->dataTransferMappingRepository->where('code', $code)->update(collect($data)->except('id')->toArray());
    }

    protected function prepareDataWithMatcher(array $item, array $matcher)
    {
        return collect($matcher)->filter(function ($woocommerceKey, $unoPimKey) use ($item) {
            return array_key_exists($unoPimKey, $item) && $item[$unoPimKey] !== '' && $item[$unoPimKey] !== null;
        })->map(function ($woocommerceKey, $unoPimKey) use ($item) {
            return [$woocommerceKey => $item[$unoPimKey]];
        })->collapse()->toArray();
    }

    // TODO -> Remove this function and use new apiendpoint getProductWithSku to create or update product based on if it exists.
    protected function handleAfterApiRequest($item, $result, $attributeId = null, $mapping = null)
    {
        $code = ! empty($result['code']) ? $result['code'] : 0;
        $requiredParams = ! empty($this->requiredApiParams) ? $this->requiredApiParams : [];
        $requiredParams['credential'] = $this->credential?->id ?? $this->credential['id'];

        if ($attributeId) {
            $requiredParams['attribute'] = $attributeId;
        }

        switch ($code) {
            case self::CODE_DUPLICATE_EXIST:
                break;

            case self::CODE_NOT_EXIST:
                break;
            case self::CODE_ALREADY_EXIST:
                $resourceId = ! empty($result['data']['resource_id']) ? $result['data']['resource_id'] : null;

                if ($resourceId) {
                    $reResult = $this->connectorService->requestApiAction(
                        self::ACTION_UPDATE,
                        $this->formatData($item),
                        array_merge($requiredParams, ['id' => $resourceId])
                    );

                    if (! self::RELATED_INDEX || isset($reResult[self::RELATED_INDEX])) {
                        $this->createDataTransferMapping($item['code'], $resourceId, $reResult[self::RELATED_INDEX] ?? null, self::UNOPIM_ENTITY_NAME);
                    }

                    return $reResult;
                }

                break;
            case self::TERM_ALREADY_EXIST:
                $resourceId = ! empty($result['data']['resource_id']) ? $result['data']['resource_id'] : null;
                if ($resourceId) {
                    $reResult = $this->connectorService->requestApiAction(
                        self::ACTION_OPTION_UPDATE,
                        $this->formatData($item),
                        array_merge($requiredParams, ['id' => $resourceId])
                    );

                    if (! self::RELATED_INDEX || isset($reResult[self::RELATED_INDEX])) {
                        $this->createDataTransferMapping($item['code'], $resourceId, $attributeId, self::ATTRIBUTE_OPTION_ENTITY_NAME);
                    }

                    return $reResult;
                }
                break;

            case 'woocommerce_rest_cannot_create':
            case 'woocommerce_rest_cannot_edit':
                if (! empty(self::ACTION_GET)) {
                    static $allResults;
                    if (empty($allResults)) {
                        $allResults = $this->connectorService->requestApiAction(
                            self::ACTION_GET,
                            [],
                            ['search' => $item['code'], 'credential' => $this->credential['id']]
                        );
                    }

                    foreach ($allResults as $key => $result) {
                        if (is_array($result) && ! empty($result['slug']) && ! empty($result['id'])) {

                            if ($result['slug'] == $item['code'] || $result['slug'] == 'pa_'.$item['code']) {
                                $this->createDataTransferMapping($item['code'], $result['id']);
                                break;
                            }
                        }
                    }
                }
                break;
            case 'woocommerce_rest_product_invalid_id':
            case 'woocommerce_rest_taxonomy_invalid':
                break;
            case 'woocommerce_rest_authentication_error':
                if (! empty($result['message'])) {
                    $this->export->state = ExportHelper::STATE_FAILED;
                    $this->export->errors = [$result['message']];
                    $this->export->save();
                }
                break;
            case JsonResponse::HTTP_OK:
            case JsonResponse::HTTP_CREATED:
                if ($mapping) {
                    $this->updateDataTransferMappingByCode($item['code'], $mapping[0]);
                } else {
                    $relatedId = isset($result['parent']) && $result['parent'] > 0 ? $result['parent'] : null;
                    $this->createDataTransferMapping($item['code'], $result['id'], $relatedId);
                }

                $images = ! empty($result['images']) ? $result['images'] : null;

                if ($images) {
                    foreach ($images as $image) {
                        $imageId = ! empty($image['id']) ? $image['id'] : null;

                        if ($imageId) {
                            $code = $item['code'].'-'.$image['name'];

                            $imageMapping = $this->getDataTransferMapping($code, 'image');

                            if ($imageMapping) {
                                $this->updateDataTransferMappingByCode($code, $imageMapping[0]);
                            } else {
                                $this->createDataTransferMapping($code, $imageId, null, 'image');
                            }
                        }
                    }
                }

                $image = ! empty($result['image']) ? $result['image'] : null;

                if ($image) {
                    $imageId = ! empty($image['id']) ? $image['id'] : null;

                    if ($imageId) {
                        $code = $item['code'].'-'.$image['yt'];
                        $imageMapping = $this->getDataTransferMapping($code, 'image');

                        if ($imageMapping) {
                            $this->updateDataTransferMappingByCode($code, $imageMapping[0]);
                        } else {
                            $this->createDataTransferMapping($code, $imageId, null, 'image');
                        }
                    }
                }

                return $result;
                break;
        }
    }

    protected function createOptions($code, $value, $locale, $entityName)
    {
        $formattedData = [];
        $attributeId = null;
        $attributeMapping = $this->getDataTransferMapping($code, 'attribute');

        if ($attributeMapping) {
            $attributeId = [
                'attribute' => $attributeMapping[0]['externalId'],
            ];
            $formattedData = [
                'name' => $value,
                'slug' => $this->connectorService->convertToCode($value),
            ];
        } else {
            $this->jobLogger->log('warning', 'Error creating option, make sure the custom attribute is exported to the woocommerce first using attribute export job.');

            return;
        }

        $optionMapping = $this->getDataTransferMapping($value, $entityName);

        if ($optionMapping) {
            $result = $this->connectorService->requestApiAction('updateOption', $formattedData, array_merge($attributeId, ['id' => $optionMapping[0]['externalId'], 'credential' => $this->credential['id']]));

            $this->handleAttributeOption($value, $result, $attributeId, $optionMapping);
        } else {
            $result = $this->connectorService->requestApiAction('addOption', $formattedData, array_merge($attributeId, ['credential' => $this->credential['id']]));

            $this->handleAttributeOption($value, $result, $attributeId);
        }
    }

    protected function handleAttributeOption($code, $result, $attributeId, $mapping = null)
    {
        $resourceId = $result['id'] ?? null;
        if ($mapping) {
            $this->updateDataTransferMappingByCode($code, $mapping[0]);
        } else {
            $this->createDataTransferMapping($code, $resourceId, $attributeId['attribute'], self::ATTRIBUTE_OPTION_ENTITY_NAME);
        }
    }

    /**
     * Check if the mapping exists in the database for the given item.
     */
    protected function checkMappingInDb(array $item, string $entity = self::UNOPIM_ENTITY_NAME): ?array
    {
        $code = $item['code'] ?? null;
        if ($code) {
            $mappingCheck = $this->dataTransferMappingRepository->where('code', $code)
                ->where('entityType', $entity)
                ->where('apiUrl', $this?->credential?->shopUrl)
                ->get();

            return $mappingCheck->toArray();
        }

        return null;
    }

    /**
     * Create a parent mapping.
     */
    protected function parentMapping(string $code, string $id, int $exportId, $productId = null): void
    {
        $mappingData = [
            'entityType'          => self::UNOPIM_ENTITY_NAME,
            'code'                => $code,
            'externalId'          => $id,
            'relatedId'           => $productId,
            'jobInstanceId'       => $exportId,
            'apiUrl'              => is_array($this->credential) ? $this->credential['shopUrl'] : null,
        ];

        $this->dataTransferMappingRepository->create($mappingData);
    }
}
