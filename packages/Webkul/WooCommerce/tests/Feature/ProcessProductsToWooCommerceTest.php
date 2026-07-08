<?php

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Webkul\WooCommerce\DTO\ProductBatch;
use Webkul\WooCommerce\Helpers\Exporters\Product\Exporter;
use Webkul\WooCommerce\Listeners\ProcessProductsToWooCommerce;
use Webkul\WooCommerce\Repositories\DataTransferMappingRepository;
use Webkul\WooCommerce\Services\WooCommerceService;

it('saves a helpful error message when WooCommerce rejects the product due to missing variations', function () {
    // Arrange
    $product = Product::factory()->simple()->create();

    $credential = [
        'id'      => 1,
        'shopUrl' => 'https://test.example.com',
        'extras'  => [
            'quicksettings' => [
                'auto_sync'      => 1,
                'quick_channel'  => 'default',
                'quick_locale'   => 'en_US',
                'quick_currency' => 'EUR',
            ],
        ],
    ];

    Cache::put('wc_default_credential', $credential, 300);

    $wcErrorResponse = [
        'code'    => 400,
        'message' => 'Ongeldige parameter(s): default_attributes',
        'data'    => [
            'status'  => 400,
            'params'  => [
                'default_attributes' => 'default_attributes[0][option] is niet van het type string.',
            ],
            'details' => [
                'default_attributes' => [
                    'code'    => 'rest_invalid_type',
                    'message' => 'default_attributes[0][option] is niet van het type string.',
                    'data'    => ['param' => 'default_attributes[0][option]'],
                ],
            ],
        ],
    ];

    $exporter = $this->mock(Exporter::class);
    $exporter->shouldReceive('initMappingsAndAttribute')->once();
    $exporter->shouldReceive('setMediaExport')->with(true)->once();
    $exporter->shouldReceive('formatData')
        ->with(Mockery::type(ProductBatch::class))
        ->once()
        ->andReturn([
            'sku'    => $product->sku,
            'name'   => $product->sku,
            'type'   => 'simple',
            'status' => 'draft',
        ]);

    $connectorService = $this->mock(WooCommerceService::class);
    $connectorService->shouldReceive('requestApiAction')
        ->with('getProductWithSku', [], Mockery::any())
        ->andReturn([]);
    $connectorService->shouldReceive('requestApiAction')
        ->with('addProduct', Mockery::any(), Mockery::any())
        ->andReturn($wcErrorResponse);

    $mappingRepo = $this->mock(DataTransferMappingRepository::class);
    $mappingRepo->shouldReceive('where')->andReturnSelf();
    $mappingRepo->shouldReceive('get')->andReturn(collect());

    // Act — a parent with no variants is a terminal data issue, so the job
    // records it on the product and returns without throwing (no pointless
    // retries), unlike the transient timeout/bad-gateway paths.
    ProcessProductsToWooCommerce::dispatchSync(
        ProductBatch::fromProductArray(['sku' => $product->sku, 'parent_id' => null, 'variants' => []])
    );

    // Assert
    $product->refresh();
    expect($product->additional['product_sync_error'])
        ->toContain('no variants');
});

it('flags attributes that WooCommerce silently dropped from the saved product', function () {
    // Arrange
    $product = Product::factory()->simple()->create();

    $credential = [
        'id'      => 1,
        'shopUrl' => 'https://test.example.com',
        'extras'  => [
            'quicksettings' => [
                'auto_sync'      => 1,
                'quick_channel'  => 'default',
                'quick_locale'   => 'en_US',
                'quick_currency' => 'EUR',
            ],
        ],
    ];

    Cache::put('wc_default_credential', $credential, 300);

    $exporter = $this->mock(Exporter::class);
    $exporter->shouldReceive('initMappingsAndAttribute')->once();
    $exporter->shouldReceive('setMediaExport')->with(true)->once();
    $exporter->shouldReceive('formatData')
        ->with(Mockery::type(ProductBatch::class))
        ->once()
        ->andReturn([
            'sku'        => $product->sku,
            'name'       => $product->sku,
            'type'       => 'variable',
            'status'     => 'publish',
            'attributes' => [
                ['id' => '11', 'visible' => true, 'variation' => false, 'options' => ['11 mm']],
                ['id' => '1', 'visible' => true, 'variation' => false, 'options' => ['Eurogros']],
            ],
        ]);

    $connectorService = $this->mock(WooCommerceService::class);
    $connectorService->shouldReceive('requestApiAction')
        ->with('getProductWithSku', [], Mockery::any())
        ->andReturn([]);
    // WooCommerce answers 201 but only persisted attribute 1 — id 11 does not exist in the shop.
    $connectorService->shouldReceive('requestApiAction')
        ->with('addProduct', Mockery::any(), Mockery::any())
        ->andReturn([
            'code'       => 201,
            'id'         => 987,
            'attributes' => [
                ['id' => 1, 'name' => 'Merk', 'options' => ['Eurogros']],
            ],
        ]);

    $mappingRepo = $this->mock(DataTransferMappingRepository::class);
    $mappingRepo->shouldReceive('where')->andReturnSelf();
    $mappingRepo->shouldReceive('whereIn')->andReturnSelf();
    $mappingRepo->shouldReceive('get')->andReturn(collect());
    $mappingRepo->shouldReceive('pluck')->andReturn(collect(['11' => 'poolhoogte']));

    \Sentry::shouldReceive('captureMessage')->once();

    // Act
    ProcessProductsToWooCommerce::dispatchSync(
        ProductBatch::fromProductArray(['sku' => $product->sku, 'parent_id' => null, 'variants' => []])
    );

    // Assert
    $product->refresh();
    expect($product->additional['product_sync_error'])
        ->toContain('poolhoogte')
        ->toContain('WooCommerce id 11');
});

it('does not flag anything when WooCommerce persisted every sent attribute', function () {
    // Arrange
    $product = Product::factory()->simple()->create();

    $credential = [
        'id'      => 1,
        'shopUrl' => 'https://test.example.com',
        'extras'  => [
            'quicksettings' => [
                'auto_sync'      => 1,
                'quick_channel'  => 'default',
                'quick_locale'   => 'en_US',
                'quick_currency' => 'EUR',
            ],
        ],
    ];

    Cache::put('wc_default_credential', $credential, 300);

    $exporter = $this->mock(Exporter::class);
    $exporter->shouldReceive('initMappingsAndAttribute')->once();
    $exporter->shouldReceive('setMediaExport')->with(true)->once();
    $exporter->shouldReceive('formatData')
        ->with(Mockery::type(ProductBatch::class))
        ->once()
        ->andReturn([
            'sku'        => $product->sku,
            'name'       => $product->sku,
            'type'       => 'variable',
            'status'     => 'publish',
            'attributes' => [
                ['id' => '1', 'visible' => true, 'variation' => false, 'options' => ['Eurogros']],
            ],
        ]);

    $connectorService = $this->mock(WooCommerceService::class);
    $connectorService->shouldReceive('requestApiAction')
        ->with('getProductWithSku', [], Mockery::any())
        ->andReturn([]);
    $connectorService->shouldReceive('requestApiAction')
        ->with('addProduct', Mockery::any(), Mockery::any())
        ->andReturn([
            'code'       => 201,
            'id'         => 987,
            'attributes' => [
                ['id' => 1, 'name' => 'Merk', 'options' => ['Eurogros']],
            ],
        ]);

    $mappingRepo = $this->mock(DataTransferMappingRepository::class);
    $mappingRepo->shouldReceive('where')->andReturnSelf();
    $mappingRepo->shouldReceive('get')->andReturn(collect());

    // Act
    ProcessProductsToWooCommerce::dispatchSync(
        ProductBatch::fromProductArray(['sku' => $product->sku, 'parent_id' => null, 'variants' => []])
    );

    // Assert
    $product->refresh();
    expect($product->additional['product_sync_error'] ?? null)->toBeNull();
});

it('reports a failed WooCommerce option creation and stores the mapping without external id', function () {
    // Arrange
    $instance = new class
    {
        use \Webkul\WooCommerce\Traits\DataTransferMappingTrait;

        public const ATTRIBUTE_OPTION_ENTITY_NAME = 'option';

        public const UNOPIM_ENTITY_NAME = 'product';

        public $credential = ['id' => 1, 'shopUrl' => 'https://test.example.com'];

        public $export = null;

        public $dataTransferMappingRepository;

        public function callHandleAttributeOption(...$args): void
        {
            $this->handleAttributeOption(...$args);
        }
    };

    $mappingRepo = Mockery::mock(DataTransferMappingRepository::class);
    $mappingRepo->shouldReceive('create')
        ->once()
        ->with(Mockery::on(fn ($data) => $data['code'] === '11 mm'
            && $data['externalId'] === null
            && $data['relatedId'] === '11'
            && $data['entityType'] === 'option'));

    $instance->dataTransferMappingRepository = $mappingRepo;

    \Sentry::shouldReceive('captureMessage')->once();

    // Act — WooCommerce rejected the term creation (e.g. the attribute id no longer exists)
    $instance->callHandleAttributeOption(
        '11 mm',
        ['code'      => 'woocommerce_rest_taxonomy_invalid', 'message' => 'Resource does not exist.'],
        ['attribute' => '11']
    );
});
