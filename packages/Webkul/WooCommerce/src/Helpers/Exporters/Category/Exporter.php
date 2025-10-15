<?php

namespace Webkul\WooCommerce\Helpers\Exporters\Category;

use Illuminate\Support\Facades\Event;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Core\Repositories\ChannelRepository;
use Webkul\DataTransfer\Contracts\JobTrackBatch as JobTrackBatchContract;
use Webkul\DataTransfer\Helpers\Export as ExportHelper;
use Webkul\DataTransfer\Helpers\Exporters\AbstractExporter;
use Webkul\DataTransfer\Jobs\Export\File\FlatItemBuffer as FileExportFileBuffer;
use Webkul\DataTransfer\Repositories\JobTrackBatchRepository;
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

    public const PARENT_CATEGORY_KEY = 'parent_category';

    public const CATEGORY_MAIN_FIELD = ['is_active', 'include_in_menu'];

    public const COLLECTION_NOT_EXIST = 'Category does not exist';

    public const UNOPIM_ENTITY_NAME = 'category';

    public const CATEGORY_FIELD = 'category_field';

    public const ACTION_ADD = 'addCategory';

    public const ACTION_UPDATE = 'updateCategory';

    public const SETTING_SECTION = 'woocommerce_connector_settings';

    public const CODE_ALREADY_EXIST = 'term_exists';

    public const CODE_DUPLICATE_EXIST = 'duplicate_term_slug';

    public const CODE_NOT_EXIST = 'woocommerce_rest_term_invalid';

    public const TERM_ALREADY_EXIST = 'term_exists';

    public const RELATED_INDEX = 'parent';

    public const WPML_CODE_ALREADY_EXIST = 'A term with the name provided already exists with this parent.';

    public const WPML_GET_DATA = 'getCategory';

    public const WPML_INVALID_CODE = 'invalid_term';

    public const ACTION_GET = '';

    public const ACTION_GET_SYSTEM_STATUS = 'getSystemStatus';

    protected $defaultStoreCategory = [];

    protected $credential;

    protected $selectedLocale;

    protected bool $exportsFile = false;

    /**
     * Create a new instance of the exporter.
     */
    public function __construct(
        protected JobTrackBatchRepository $exportBatchRepository,
        protected FileExportFileBuffer $exportFileBuffer,
        protected CredentialRepository $credentialRepository,
        protected DataTransferMappingRepository $dataTransferMappingRepository,
        protected WooCommerceService $connectorService,
        protected ChannelRepository $channelRepository,
        protected CategoryRepository $categoryRepository
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

        $this->selectedLocale = $this->jobFilters['locale'];
    }

    /**
     * Start the export process
     */
    public function exportBatch(JobTrackBatchContract $batch, $filePath): bool
    {
        Event::dispatch('woocommerce.category.export.before', $batch);

        $this->initialize();

        $this->exportCategory($batch);

        /**
         * Update export batch process state summary
         */
        $this->updateBatchState($batch->id, ExportHelper::STATE_PROCESSED);

        Event::dispatch('woocommerce.category.export.after', $batch);

        return true;
    }

    public function exportCategory(JobTrackBatchContract $batch)
    {
        foreach ($batch->data as $rawData) {
            $mapping = $this->credentialRepository->find($this->credential->id)['extras'];

            $allCategories = [$rawData];
            if (! empty($rawData['descendants'])) {
                $childs = $rawData['descendants'];
                unset($rawData['descendants']);
                $rawData = [$rawData];
                $allCategories = array_merge($rawData, $childs);
            }

            foreach ($allCategories as $category) {
                $mapping = $this->getDataTransferMapping($category['code']) ?? null;
                if (empty($mapping)) {
                    $response = $this->createCategory($category);

                    $this->handleAfterApiRequest($category, $response, null, null);
                }

                if (! empty($mapping)) {
                    $id = (int) $mapping[0]['externalId'];
                    $response = $this->updateCategory($category, $id);
                    $this->handleAfterApiRequest($category, $response, null, $mapping);
                }

                if (isset($response['error'])) {
                    $this->logWarning($response, $category['code']);
                    $this->skippedItemsCount++;

                    continue;
                }

                $this->createdItemsCount++;

            }
        }
    }

    public function formatData(array $rawData): array
    {
        $locale = $this->selectedLocale;
        $categoryFields = $this->getLocaleSpecificFields($rawData, $locale);

        $data['parent'] = $this->getParentCategoryId($rawData);
        $data['name'] = $categoryFields ? $categoryFields['name'] : $rawData['code'];
        $data['slug'] = $rawData['code'];
        $data['description'] = $categoryFields['description'] ?? '';

        $this->setCategoryMainFields($data, $categoryFields);

        return $data;
    }

    /**
     * log Warning generate
     */
    public function logWarning(array $data, string $code): void
    {
        if (! empty($data) && ! empty($code)) {
            $error = json_encode($data, true);
            $this->jobLogger->warning(
                "Warning for Category with code: {$code}, : {$error}"
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function getResults()
    {
        $filters = $this->getFilters();
        $ids = $this->getRootCategoryIds($filters);

        return $this->source
            ->whereIn('id', $ids)
            ->where('parent_id', null)
            ->with('descendants')
            ->with('parent_category')
            ->get()
            ?->getIterator();
    }

    protected function getRootCategoryIds($filters): array
    {
        $categoryIds = [];

        if (isset($filters['channel']) && $filters['channel']) {
            $channelCodes = is_array($filters['channel']) ? $filters['channel'] : [$filters['channel']];
            $channels = $this->channelRepository->findWhereIn('code', $channelCodes);
            $categoryIds = $channels->pluck('root_category.id')->unique()->values()->all() ?? [];
        }

        return $categoryIds;
    }

    protected function createCategory(array $rawData): array
    {
        $payload = $this->formatData($rawData);

        $response = $this->connectorService->requestApiAction(self::ACTION_ADD, $payload, ['credential' => $this->credential->id]);

        return $response;
    }

    protected function updateCategory(array $rawData, int $id, $locale = null): array
    {
        if (! $locale) {
            $locale = $this->selectedLocale;
        }

        $payload = $this->formatData($rawData);

        $response = $this->connectorService->requestApiAction(self::ACTION_UPDATE, $payload, ['credential' => $this->credential->id, 'id' => $id]);

        return $response;
    }

    protected function setCategoryMainFields(array &$data, array $categoryFields)
    {
        if (! $categoryFields) {
            $data['is_active'] = true;
        }

    }

    protected function getParentCategoryId(array $rawData): ?int
    {
        $id = null;

        if (isset($rawData['parent_id']) && $rawData['parent_id'] !== null) {
            $category = $this->categoryRepository->findOneWhere(['id' => $rawData['parent_id']]);
            $code = $category ? $category->code : null;

            if ($code) {
                $mapping = $this->getDataTransferMapping($code) ?? null;

                if ($mapping) {
                    $id = (int) $mapping[0]['externalId'];
                }
            }
        }

        return $id;
    }

    /**
     * Get locale-specific fields from the raw data.
     */
    private function getLocaleSpecificFields(array $data, ?string $locale): array
    {
        if (! is_array($data['additional_data'])) {
            return [];
        }

        if (! array_key_exists('additional_data', $data) || ! array_key_exists('locale_specific', $data['additional_data'])) {
            return [];
        }

        return $data['additional_data']['locale_specific'][$locale] ?? [];
    }
}
