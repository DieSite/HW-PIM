<?php

namespace Webkul\WooCommerce\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Attribute\Repositories\AttributeFamilyRepository;
use Webkul\Attribute\Repositories\AttributeGroupRepository;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Core\Eloquent\Repository;
use Webkul\Core\Eloquent\TranslatableModel;
use Webkul\Core\Repositories\ChannelRepository;
use Webkul\Core\Repositories\CurrencyRepository;
use Webkul\Core\Repositories\LocaleRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\WooCommerce\Repositories\AttributeMappingRepository;
use Webkul\WooCommerce\Repositories\CredentialRepository;

class OptionController extends Controller
{
    const DEFAULT_PER_PAGE = 20;

    const EXCLUDE_MEDIA_TYPE = ['images', 'gallery'];

    const STORE_URL_FILTER = 'credential';

    const PRODUCT_ENTITY_NAME = 'product';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected CredentialRepository $woocommerceCredentialRepository,
        protected AttributeRepository $attributeRepository,
        protected ChannelRepository $channelRepository,
        protected CurrencyRepository $currencyRepository,
        protected LocaleRepository $localeRepository,
        protected AttributeGroupRepository $attributeGroupRepository,
        protected AttributeFamilyRepository $attributeFamilyRepository,
        protected ProductRepository $productRepository,
        protected AttributeMappingRepository $attributeMappingRepository,
    ) {}

    /**
     * Return All credentials
     */
    public function listWooCommerceCredential(): JsonResponse
    {
        $queryParams = request()->except(['page', 'query', 'entityName', 'attributeId']);
        $query = request()->get('query') ?? null;
        $woocommerceRepo = $this->woocommerceCredentialRepository;
        if ($query) {
            $woocommerceRepo = $woocommerceRepo->where('shopUrl', 'LIKE', '%'.$query.'%');
        }

        $searchIdentifiers = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];

        $woocommerceCredentialRepository = $woocommerceRepo->where('active', 1);

        if (! empty($searchIdentifiers)) {
            $values = $searchIdentifiers['values'] ?? [];

            $woocommerceCredentialRepository = $woocommerceCredentialRepository->whereIn(
                'id',
                is_array($values) ? $values : [$values]
            );
        }

        $allActivateCredntial = $woocommerceCredentialRepository->get()->toArray();
        $allCredential = [];

        foreach ($allActivateCredntial as $credentialArray) {
            $allCredential[] = [
                'id'    => $credentialArray['id'],
                'label' => $credentialArray['shopUrl'],
            ];
        }

        return new JsonResponse([
            'options' => $allCredential,
        ]);
    }

    /**
     * Return All Channels
     */
    public function listChannel(): JsonResponse
    {
        $queryParams = request()->except(['page', 'query', 'entityName', 'attributeId']);
        $searchIdentifiers = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];

        $channelRepository = $this->channelRepository;

        if (! empty($searchIdentifiers)) {
            $values = $searchIdentifiers['values'] ?? [];

            $channelRepository = $channelRepository->whereIn(
                'code',
                is_array($values) ? $values : [$values]
            );
        }

        $allActivateChannel = $channelRepository->get()->toArray();

        $allChannel = [];

        foreach ($allActivateChannel as $channel) {
            $allChannel[] = [
                'id'    => $channel['code'],
                'label' => $channel['name'] ?? $channel['code'],
            ];
        }

        return new JsonResponse([
            'options' => $allChannel,
        ]);
    }

    /**
     * Return All Currency
     */
    public function listCurrency(): JsonResponse
    {
        $queryParams = request()->except(['page', 'query', 'entityName', 'attributeId']);

        $searchIdentifiers = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];

        $currencyRepository = $this->currencyRepository->where('status', 1);

        if (! empty($searchIdentifiers)) {
            $values = $searchIdentifiers['values'] ?? [];

            $currencyRepository = $currencyRepository->whereIn(
                'code',
                is_array($values) ? $values : [$values]
            );
        }

        $allActivateCurrency = $currencyRepository->get()->toArray();

        $allCurrency = array_map(function ($item) {
            return [
                'id'    => $item['code'],
                'label' => $item['name'],
            ];
        }, $allActivateCurrency);

        return new JsonResponse([
            'options' => $allCurrency,
        ]);
    }

    /**
     * Return All Locale
     */
    public function listLocale(): JsonResponse
    {
        $queryParams = request()->except(['page', 'query', 'entityName', 'attributeId']);
        $localeRepository = $this->localeRepository;
        $query = request()->get('query');
        if ($query) {
            $localeRepository = $localeRepository->where('code', 'LIKE', '%'.$query.'%');
        }

        $searchIdentifiers = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];

        $localeRepository = $localeRepository->where('status', 1);

        if (! empty($searchIdentifiers)) {
            $values = $searchIdentifiers['values'] ?? [];

            $localeRepository = $localeRepository->whereIn(
                'code',
                is_array($values) ? $values : [$values]
            );
        }

        $allActivateLocale = $localeRepository->get()->toArray();

        $allLocale = array_map(function ($item) {
            return [
                'id'    => $item['code'],
                'label' => $item['name'],
            ];
        }, $allActivateLocale);

        return new JsonResponse([
            'options' => $allLocale,
        ]);
    }

    /**
     * List attributes based on entity and query filters.
     */
    public function listAttributes(): JsonResponse
    {
        $entityName = request()->get('entityName') !== '[]' ? request()->get('entityName') : [];
        $notInclude = request()->get(0) ?? '';
        $fieldName = request()->get(1) ?? '';
        $page = request()->get('page');
        $query = request()->get('query') ?? '';
        $queryParams = request()->except(['page', 'query', 'entityName', 'attributeId', 'notInclude']);
        $attributeRepository = $this->attributeRepository;
        if (! empty($entityName)) {
            $entityName = json_decode($entityName);
            $attributeRepository = in_array('number', $entityName)
                ? $attributeRepository->whereIn('validation', $entityName)
                : $attributeRepository->whereIn('type', $entityName);
        }

        if (! empty($query)) {
            $attributeRepository = $attributeRepository->where('code', 'LIKE', '%'.$query.'%');
        }

        $searchIdentifiers = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];

        if (! empty($searchIdentifiers)) {
            $values = $searchIdentifiers['values'] ?? [];
            $attributeRepository = $attributeRepository->whereIn(
                $searchIdentifiers['columnName'],
                is_array($values) ? $values : [$values]
            );
            if (! empty($notInclude)) {
                $notIncludeValues = array_values(array_diff(array_values($notInclude), $values));
                $attributeRepository = $attributeRepository->whereNotIn('code', $notIncludeValues);
            }
        } else {
            if (! empty($notInclude)) {
                unset($notInclude[$fieldName]);
                $attributeRepository = $attributeRepository->whereNotIn('code', array_values($notInclude));
            }
        }

        $attributes = $attributeRepository->orderBy('id')->paginate(20, ['*'], 'paginate', $page);

        $formattedoptions = [];

        $currentLocaleCode = core()->getRequestedLocaleCode();

        foreach ($attributes as $attribute) {
            $translatedLabel = $attribute->translate($currentLocaleCode)?->name;
            $formattedoptions[] = [
                'id'    => $attribute->id,
                'code'  => $attribute->code,
                'label' => ! empty($translatedLabel) ? $translatedLabel : "[{$attribute->code}]",
            ];
        }

        return new JsonResponse([
            'options'  => $formattedoptions,
            'page'     => $attributes->currentPage(),
            'lastPage' => $attributes->lastPage(),
        ]);
    }

    /**
     * Fetch and format options for async select and multiselect handlers
     */
    public function listCustomAttributes()
    {
        $entityName = 'attributes';
        $page = request()->get('page');
        $credentialId = request()->get('credential') ?? null;
        $query = request()->get('query') ?? '';
        $queryParams = request()->except(['page', 'query', 'entityName']);
        $options = $this->getOptionsByParams($entityName, $page, $query, $queryParams);
        $currentLocaleCode = core()->getRequestedLocaleCode();
        $formattedoptions = [];

        foreach ($options as $option) {
            $formattedoptions[] = $this->formatOption($option, $currentLocaleCode);
        }
        if ($credentialId) {
            $formattedoptions = $this->filterCustomAttributes($formattedoptions, $credentialId);
        }

        return new JsonResponse([
            'options'  => $formattedoptions,
            'page'     => $options->currentPage(),
            'lastPage' => $options->lastPage(),
        ]);
    }

    /**
     * This is used when the select field is initially loaded on page with selected value
     * if the selected value is not in a format for select field the value is fetched from repo
     */
    protected function applyInitialValues($repository, array $initializeValues)
    {
        return $repository->whereIn(
            $initializeValues['columnName'],
            is_array($initializeValues['values']) ? $initializeValues['values'] : [$initializeValues['values']]
        );
    }

    protected function filterCustomAttributes(array $formattedoptions, $credentialId): array
    {
        $customAttributes = $this->attributeMappingRepository->getCustomAttributesMapping($credentialId);

        $customAttributes = array_filter($formattedoptions, function ($item) use ($customAttributes) {
            return in_array($item['code'], $customAttributes);
        });
        $customAttributes = array_values($customAttributes);

        return $customAttributes;
    }

    /**
     * List image attributes.
     */
    public function listImageAttributes(): JsonResponse
    {
        $query = request()->get('query') ?? '';

        $queryParams = request()->except(['page', 'query', 'entityName', 'attributeId']);
        $formattedoptions = [];

        if (isset($queryParams['mediaType'])) {
            $attributeRepository = $this->attributeRepository->where('type', $queryParams['mediaType']);
        } else {
            $attributeRepository = $this->attributeRepository;
        }
        $currentLocaleCode = core()->getRequestedLocaleCode();

        if (! empty($query)) {
            $attributeRepository = $attributeRepository->where('code', 'LIKE', '%'.$query.'%');
        }

        $searchIdentifiers = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];

        if (! empty($searchIdentifiers)) {
            $values = $searchIdentifiers['values'] ?? [];

            $attributeRepository = $attributeRepository->whereIn(
                $searchIdentifiers['columnName'],
                is_array($values) ? $values : [$values]
            );
        }

        $attributes = $attributeRepository->get();

        foreach ($attributes as $attribute) {
            $translatedLabel = $attribute->translate($currentLocaleCode)?->name;
            $formattedoptions[] = [
                'id'    => $attribute->id,
                'code'  => $attribute->code,
                'label' => ! empty($translatedLabel) ? $translatedLabel : "[{$attribute->code}]",
            ];
        }

        return new JsonResponse([
            'options' => $formattedoptions,
        ]);
    }

    /**
     * List Gallery attributes.
     */
    public function listGalleryAttributes(): JsonResponse
    {
        $query = request()->get('query') ?? '';

        $queryParams = request()->except(['page', 'query', 'entityName', 'attributeId']);

        $attributeRepository = $this->attributeRepository->where('type', 'gallery');

        $currentLocaleCode = core()->getRequestedLocaleCode();

        if (! empty($query)) {
            $attributeRepository = $attributeRepository->where('code', 'LIKE', '%'.$query.'%');
        }

        $searchIdentifiers = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];

        if (! empty($searchIdentifiers)) {
            $values = $searchIdentifiers['values'] ?? [];

            $attributeRepository = $attributeRepository->whereIn(
                $searchIdentifiers['columnName'],
                is_array($values) ? $values : [$values]
            );
        }

        $attributes = $attributeRepository->get();

        $formattedoptions = [];

        foreach ($attributes as $attribute) {
            $translatedLabel = $attribute->translate($currentLocaleCode)?->name;
            $formattedoptions[] = [
                'id'    => $attribute->id,
                'code'  => $attribute->code,
                'label' => ! empty($translatedLabel) ? $translatedLabel : "[{$attribute->code}]",
            ];
        }

        return new JsonResponse([
            'options' => $formattedoptions,
        ]);
    }

    public function listMetafieldAttributes(): JsonResponse
    {
        $queryParams = request()->except(['page', 'query', 'attributeId']);

        $query = request()->get('query') ?? '';
        $credentialData = $this->woocommerceCredentialRepository->find($queryParams[0]);
        $metaFieldAttr = array_merge($credentialData?->extras['productMetafield'] ?? [], $credentialData?->extras['productVariantMetafield'] ?? []);

        $searchIdentifiers = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];
        $entityName = $queryParams['entityName'];
        $attributeRepository = $this->attributeRepository->whereIn('code', $metaFieldAttr);

        if (! empty($entityName)) {
            $entityName = json_decode($entityName);
            $attributeRepository = in_array('number', $entityName)
                ? $attributeRepository->whereIn('validation', $entityName)
                : $attributeRepository->whereIn('type', $entityName);
        }

        if (! empty($query)) {
            $attributeRepository = $attributeRepository->where('code', 'LIKE', '%'.$query.'%');
        }

        if (! empty($searchIdentifiers)) {
            $values = $searchIdentifiers['values'] ?? [];

            $attributeRepository = $attributeRepository->whereIn(
                $searchIdentifiers['columnName'],
                is_array($values) ? $values : [$values]
            );
        }

        $attributes = $attributeRepository->get();

        $formattedoptions = [];
        $currentLocaleCode = core()->getRequestedLocaleCode();
        foreach ($attributes as $attribute) {
            $translatedLabel = $attribute->translate($currentLocaleCode)?->name;
            $formattedoptions[] = [
                'id'    => $attribute->id,
                'code'  => $attribute->code,
                'label' => ! empty($translatedLabel) ? $translatedLabel : "[{$attribute->code}]",
            ];
        }

        return new JsonResponse([
            'options' => $formattedoptions,
        ]);
    }

    /**
     * Fetch and format options for async select and multiselect handlers
     */
    public function getAdditionalAttributeOptions()
    {
        $entityName = request()->get('entityName');
        $credentialId = request()->get('credentialId');
        $page = request()->get('page');
        $query = request()->get('query') ?? '';
        $queryParams = request()->except(['page', 'query', 'entityName']);

        $options = $this->getOptionsByParams($entityName, $page, $query, $queryParams);
        $currentLocaleCode = core()->getRequestedLocaleCode();
        $formattedoptions = [];

        foreach ($options as $option) {
            $formattedoptions[] = $this->formatOption($option, $currentLocaleCode);
        }

        $this->excludeAttributeMappingFields($formattedoptions, $credentialId);

        return new JsonResponse([
            'options'  => $formattedoptions,
            'page'     => $options->currentPage(),
            'lastPage' => $options->lastPage(),
        ]);
    }

    public function getMediaAttributeOptions()
    {
        $entityName = request()->get('entityName');
        $page = request()->get('page');
        $query = request()->get('query') ?? '';
        $queryParams = request()->except(['page', 'query', 'entityName']);
        $attributeTypes = ['image'];

        $options = $this->getOptionsByParams($entityName, $page, $query, $queryParams, $attributeTypes);
        $currentLocaleCode = core()->getRequestedLocaleCode();
        $formattedoptions = [];

        foreach ($options as $option) {
            $formattedoptions[] = $this->formatOption($option, $currentLocaleCode);
        }

        return new JsonResponse([
            'options'  => $formattedoptions,
            'page'     => $options->currentPage(),
            'lastPage' => $options->lastPage(),
        ]);
    }

    public function excludeAttributeMappingFields(array &$formattedoptions, $credentialId)
    {
        $filteredOptions = array_filter($formattedoptions, function ($option) {
            if (in_array($option['code'], self::EXCLUDE_MEDIA_TYPE)) {
                return false;
            }

            return true;
        });

        $formattedoptions = array_values($filteredOptions);
    }

    /**
     * List attribute Group.
     */
    public function listAttributeGroup(): JsonResponse
    {
        $queryParams = request()->except(['page', 'query', 'entityName', 'attributeId']);
        $searchIdentifiers = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];
        $attributeGroupRepository = $this->attributeGroupRepository;
        if (! empty($searchIdentifiers)) {
            $values = $searchIdentifiers['values'] ?? [];
            $attributeGroupRepository = $attributeGroupRepository->whereIn(
                'id',
                is_array($values) ? $values : [$values]
            );
        }
        $allAttributegroup = $attributeGroupRepository->get()->toArray();

        $attrGroupList = [];

        $attrGroupList = array_map(function ($item) {
            return [
                'id'    => $item['id'],
                'label' => $item['name'] ?? $item['code'],
            ];
        }, $allAttributegroup);

        return new JsonResponse([
            'options' => $attrGroupList,
        ]);
    }

    /**
     * List of family.
     */
    public function listWoocommerceFamily(): JsonResponse
    {
        $query = request()->get('query') ?? '';

        $queryParams = request()->except(['page', 'query', 'entityName', 'attributeId']);

        $attributeFamilyRepository = $this->attributeFamilyRepository;

        $currentLocaleCode = core()->getRequestedLocaleCode();

        if (! empty($query)) {
            $attributeFamilyRepository = $attributeFamilyRepository->where('code', 'LIKE', '%'.$query.'%');
        }

        $searchIdentifiers = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];

        if (! empty($searchIdentifiers)) {
            $values = $searchIdentifiers['values'] ?? [];

            $attributeFamilyRepository = $attributeFamilyRepository->whereIn(
                $searchIdentifiers['columnName'],
                is_array($values) ? $values : [$values]
            );
        }

        $attributesFamilies = $attributeFamilyRepository->get();

        $formattedoptions = [];

        foreach ($attributesFamilies as $attributesFamily) {
            $translatedLabel = $attributesFamily->translate($currentLocaleCode)?->name;
            $formattedoptions[] = [
                'id'    => $attributesFamily->id,
                'code'  => $attributesFamily->code,
                'label' => ! empty($translatedLabel) ? $translatedLabel : "[{$attributesFamily->code}]",
            ];
        }

        return new JsonResponse([
            'options' => $formattedoptions,
        ]);
    }

    /**
     * Fetch options according to parameters for search, page and id
     */
    protected function getOptionsByParams(
        string $entityName,
        int|string $page,
        string $query = '',
        ?array $queryParams = [],
        ?array $attributeTypes = []
    ): LengthAwarePaginator {
        $repository = $this->getRepository($entityName);

        if (isset($queryParams['0']) && $queryParams[0] == '0') {
            $queryParams['filters'][] = [
                'column'   => 'type',
                'operator' => 'IN',
                'value'    => ['select', 'multiselect', 'boolean'],
            ];
        }

        if (isset($queryParams['filters']) && is_array($queryParams['filters'])) {
            $repository = $this->applyFilters($repository, $queryParams['filters']);
        }

        if (! empty($query)) {
            $repository = $this->applySearchQuery($repository, $query, $entityName);
        }

        $initializeValues = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];

        if (! empty($initializeValues)) {
            $repository = $this->applyInitialValues($repository, $initializeValues);
        }

        if (! empty($attributeTypes)) {
            $repository = $repository->whereIn('type', $attributeTypes);
        }

        return $repository->orderBy('id')->paginate(self::DEFAULT_PER_PAGE, ['*'], 'paginate', $page);
    }

    /**
     * Applies search query for the select field
     */
    protected function applySearchQuery($repository, string $query, string $entityName)
    {
        return $repository->where(function ($queryBuilder) use ($query, $entityName) {
            $queryBuilder->whereTranslationLike($this->getTranslationColumnName($entityName), '%'.$query.'%')
                ->orWhere('code', $query);
        });
    }

    /**
     * Get Repository according to entity name
     */
    private function getRepository(string $entityName): Repository
    {
        return match ($entityName) {
            'attributes'         => $this->attributeRepository,
            'channels'           => $this->channelRepository,
            'locales'            => $this->localeRepository,
            'attributeFamilies'  => $this->attributeFamilyRepository,
            'product'            => $this->productRepository,
            default              => throw new \Exception('Not implemented for '.$entityName)
        };
    }

    /**
     * Get translated label for the entity
     */
    protected function getTranslatedLabel(string $currentLocaleCode, TranslatableModel $option): string
    {
        $translation = $option->translate($currentLocaleCode);

        return $translation?->label ?? $translation?->name;
    }

    /**
     * format option for select component
     */
    protected function formatOption(Model $option, string $currentLocaleCode)
    {
        $translatedOptionLabel = $this->getTranslatedLabel($currentLocaleCode, $option);

        return [
            'id'    => $option->id,
            'code'  => $option->code,
            'label' => ! empty($translatedOptionLabel) ? $translatedOptionLabel : "[{$option->code}]",
            ...$option->makeHidden(['translations'])->toArray(),
        ];
    }

    /**
     * Translation for the models label to be used for search
     */
    protected function getTranslationColumnName(string $entityName): string
    {
        return match ($entityName) {
            'attributes'        => 'name',
            'attributeFamilies' => 'name',
            default             => 'label'
        };
    }

    /**
     * Fetch and format options for async select and multiselect handlers
     */
    public function listProductSKU()
    {
        $page = request()->get('page');
        $query = request()->get('query') ?? '';
        $queryParams = request()->except(['page', 'query', 'entityName']);
        $options = $this->getProductOptionsByParams(self::PRODUCT_ENTITY_NAME, $page, $query, $queryParams);
        $currentLocaleCode = core()->getRequestedLocaleCode();
        $formattedoptions = [];

        foreach ($options as $option) {
            $formattedoptions[] = $this->formatSkuOption($option);
        }

        return new JsonResponse([
            'options'  => $formattedoptions,
            'page'     => $options->currentPage(),
            'lastPage' => $options->lastPage(),
        ]);
    }

    /**
     * format option for select component
     */
    protected function formatSkuOption(Model $option)
    {
        return [
            'id'    => $option->sku,
            'sku'   => $option->sku,
            'label' => $option->sku,
        ];
    }

    /**
     * Fetch options according to parameters for search, page and id
     */
    protected function getProductOptionsByParams(
        string $entityName,
        int|string $page,
        string $query = '',
        ?array $queryParams = []
    ): LengthAwarePaginator {
        $repository = $this->getRepository($entityName);

        if (isset($queryParams['filters']) && is_array($queryParams['filters'])) {
            $repository = $this->applyFilters($repository, $queryParams['filters']);
        }

        if (! empty($query)) {
            $repository = $this->applyProductSearchQuery($repository, $query, $entityName);
        }

        $initializeValues = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];

        if (! empty($initializeValues)) {
            $repository = $this->applyInitialValues($repository, $initializeValues);
        }

        return $repository->whereNull('parent_id')->orderBy('id')->paginate(self::DEFAULT_PER_PAGE, ['*'], 'paginate', $page);
    }

    /**
     * Apply Filters according to query on the query builder object
     */
    protected function applyFilters($repository, array $filters)
    {

        foreach ($filters as $filter) {
            $column = $filter['column'] ?? null;
            $operator = $filter['operator'] ?? '=';
            $value = $filter['value'] ?? null;

            if ($column && isset($value)) {
                $repository = $filter['operator'] === 'IN' ? $repository->orWhereIn($column, $value) : $repository->where($column, $operator, $value);
            }
        }

        return $repository;
    }

    /**
     * Applies search query for the select field
     */
    protected function applyProductSearchQuery($repository, string $query, string $entityName)
    {
        return $repository->where(function ($queryBuilder) use ($query) {
            $queryBuilder->where('sku', 'like', '%'.$query.'%')
                ->orWhere('sku', $query);
        });
    }
}
