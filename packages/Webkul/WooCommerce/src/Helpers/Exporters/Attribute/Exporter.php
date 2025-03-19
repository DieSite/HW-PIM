<?php

namespace Webkul\WooCommerce\Helpers\Exporters\Attribute;

use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\DataTransfer\Contracts\JobTrackBatch as JobTrackBatchContract;
use Webkul\DataTransfer\Helpers\Export;
use Webkul\DataTransfer\Helpers\Export as ExportHelper;
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

    public const UNOPIM_ENTITY_NAME = 'attribute';

    public const ACTION_ADD = 'addAttribute';

    public const ACTION_OPTION_ADD = 'addOption';

    public const ACTION_UPDATE = 'updateAttribute';

    public const ACTION_OPTION_UPDATE = 'updateOption';

    public const ACTION_GET = 'getAttributes';

    public const WPML_CODE_ALREADY_EXIST = 'A term with the name provided already exists with this parent.';

    public const ACTION_GET_SYSTEM_STATUS = 'getSystemStatus';

    public const SETTING_SECTION = 'woocommerce_connector_settings';

    public const CODE_ALREADY_EXIST = 'na';

    public const TERM_ALREADY_EXIST = 'term_exists';

    public const CODE_DUPLICATE_EXIST = 'na';

    public const WPML_INVALID_CODE = 'ID not exists!';

    public const CODE_NOT_EXIST = 'woocommerce_rest_term_invalid';

    public const WPML_GET_DATA = 'getAttributes';

    public const RELATED_INDEX = null;

    public const OPTIONS_KEY = 'options';

    protected $systemStatus;

    protected $selectedLocale;

    public const ATTRIBUTE_OPTION_ENTITY_NAME = 'option';

    /*
     * For exporting file
     */
    protected bool $exportsFile = false;

    /**
     * Current crenetial.
     *
     * @var array
     */
    protected $credential;

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
        $this->selectedLocale = $this->jobFilters['locale'];
    }

    /**
     * Start the export process
     */
    public function exportBatch(JobTrackBatchContract $batch, $filePath): bool
    {
        $this->initialize();

        $this->exportAttribute($batch);

        /**
         * Update export batch process state summary
         */
        $this->updateBatchState($batch->id, ExportHelper::STATE_PROCESSED);

        return true;
    }

    protected function getResults()
    {
        $codes = [];
        $filters = $this->getFilters();
        $attributeFilter = $filters['attributes'] ?? null;
        $credentialId = $filters['credential'];
        $codes = $this->connectorService->getCustomAttributes($credentialId);

        if (! empty($attributeFilter)) {
            $attributesToExport = explode(',', $attributeFilter);
            $codes = array_intersect($codes, $attributesToExport);
        }

        return $this->source->with('options')->whereIn('code', $codes)->get()->getIterator();
    }

    public function exportAttribute(JobTrackBatchContract $batch)
    {
        foreach ($batch->data as $rawData) {
            $mapping = $this->getDataTransferMapping($rawData['code']) ?? null;

            if (empty($mapping)) {
                $response = $this->createAttribute($rawData);
                $this->handleAfterApiRequest($rawData, $response, null, null);
            }

            if (! empty($mapping)) {
                $id = (int) $mapping[0]['externalId'];
                $response = $this->updateAttribute($rawData, $id);
            }

            if (! isset($response['error']) && ! empty($rawData[self::OPTIONS_KEY])) {
                $this->exportOptionsAndCreateMapping($rawData);
            }

            if (isset($response['error'])) {
                $this->logWarning($response, $rawData['code']);
                $this->skippedItemsCount++;

                continue;
            }

            $this->createdItemsCount++;
        }
    }

    protected function createAttribute(array $rawData): array
    {
        $payload = $this->prepareAttribute($rawData);

        $response = $this->connectorService->requestApiAction(self::ACTION_ADD, $payload, ['credential' => $this->credential->id]);

        return $response;
    }

    protected function updateAttribute(array $rawData, int $id): array
    {
        $payload = $this->prepareAttribute($rawData);
        $response = $this->connectorService->requestApiAction(self::ACTION_UPDATE, $payload, ['credential' => $this->credential->id, 'id' => $id]);

        return $response;
    }

    public function prepareAttribute(array $rawData, $mapping = []): array
    {
        $rawData['name'] = ! empty($rawData['name']) ? $rawData['name'] : $rawData['code'];
        $data = $this->prepareDataWithMatcher($rawData, $this->matcher);
        $data['type'] = 'select';

        return $data;
    }

    protected function exportOptionsAndCreateMapping(array $rawData)
    {
        $options = [];

        if (empty($rawData[self::OPTIONS_KEY])) {
            return $options;
        }

        foreach ($rawData[self::OPTIONS_KEY] as $option) {
            $payload = $this->formatData($option);

            $attributeMapping = $this->getDataTransferMapping($rawData['code']) ?? null;
            $attributeId = $attributeMapping[0]['externalId'] ?? null;

            $optionMapping = $this->getDataTransferMapping($option['code'], self::ATTRIBUTE_OPTION_ENTITY_NAME) ?? null;

            if (empty($optionMapping)) {
                $response = $this->connectorService->requestApiAction(self::ACTION_OPTION_ADD, $payload, ['attribute' => $attributeId, 'credential' => $this->credential->id]);

                $this->handleAfterApiRequest($option, $response, $attributeId, null);
            }

            if (! empty($optionMapping)) {
                $id = (int) $optionMapping[0]['externalId'];
                $response = $this->connectorService->requestApiAction(self::ACTION_OPTION_UPDATE, $payload, ['attribute' => $attributeId, 'credential' => $this->credential->id, 'id' => $id]);
            }
        }
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

    protected function formatData($option)
    {
        $localWiseLabel = collect($option['translations'])->firstWhere('locale', $this->selectedLocale ?? '');

        $formattedOptions = [
            'slug'        => $option['code'],
            'name'        => $localWiseLabel ? $localWiseLabel['label'] : ($option['label'] ?? $option['code']),
        ];

        return $formattedOptions;
    }

    protected $matcher = [
        'name'                   => 'name',
        'code'                   => 'slug',
    ];
}
