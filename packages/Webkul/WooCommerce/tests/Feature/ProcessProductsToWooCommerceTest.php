<?php

use App\Enums\WooCommerceSyncEventStatus;
use App\Models\Product;
use App\Models\WooCommerceSyncEvent;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Webkul\WooCommerce\DTO\ProductBatch;
use Webkul\WooCommerce\Helpers\Exporters\Product\Exporter;
use Webkul\WooCommerce\Listeners\ProcessProductsToWooCommerce;
use Webkul\WooCommerce\Repositories\DataTransferMappingRepository;
use Webkul\WooCommerce\Services\WooCommerceService;
use Webkul\WooCommerce\Traits\DataTransferMappingTrait;

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

    Sentry::shouldReceive('captureMessage')->once();

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
        use DataTransferMappingTrait;

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

    Sentry::shouldReceive('captureMessage')->once();

    // Act — WooCommerce rejected the term creation (e.g. the attribute id no longer exists)
    $instance->callHandleAttributeOption(
        '11 mm',
        ['code'      => 'woocommerce_rest_taxonomy_invalid', 'message' => 'Resource does not exist.'],
        ['attribute' => '11']
    );
});

it('deletes a trashed WooCommerce product that still claims the SKU and re-creates the product', function () {
    // Arrange
    $product = Product::factory()->simple()->create();

    Cache::put('wc_default_credential', [
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
    ], 300);

    $exporter = $this->mock(Exporter::class);
    $exporter->shouldReceive('initMappingsAndAttribute')->once();
    $exporter->shouldReceive('setMediaExport')->with(true)->once();
    $exporter->shouldReceive('formatData')->once()->andReturn([
        'sku'    => $product->sku,
        'name'   => $product->sku,
        'type'   => 'simple',
        'status' => 'publish',
    ]);

    $connectorService = $this->mock(WooCommerceService::class);
    $connectorService->shouldReceive('requestApiAction')
        ->with('getProductWithSku', [], Mockery::on(fn ($params) => ($params['status'] ?? null) === 'trash'))
        ->once()
        ->andReturn([['id' => 209302, 'sku' => $product->sku, 'status' => 'trash', 'type' => 'simple', 'parent_id' => 0], 'code' => 200]);
    $connectorService->shouldReceive('requestApiAction')
        ->with('getProductWithSku', [], Mockery::on(fn ($params) => ! isset($params['status'])))
        ->twice()
        ->andReturn(['code' => 200]);
    $connectorService->shouldReceive('requestApiAction')
        ->with('getProduct', [], Mockery::on(fn ($params) => $params['id'] === '209302'))
        ->once()
        ->andReturn(['id' => 209302, 'type' => 'simple', 'parent_id' => 0, 'code' => 200]);
    $connectorService->shouldReceive('requestApiAction')
        ->with('deleteProduct', [], Mockery::on(fn ($params) => $params['id'] === '209302' && $params['force'] === 'true'))
        ->once();
    $connectorService->shouldReceive('requestApiAction')
        ->with('addProduct', Mockery::any(), Mockery::any())
        ->twice()
        ->andReturn(
            [
                'code'    => 400,
                'message' => "Het product met SKU ({$product->sku}) dat je probeert in te voegen, is al aanwezig in de zoektabel",
                'data'    => ['status' => 400],
            ],
            ['code' => 201, 'id' => 300001, 'attributes' => []],
        );

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

it('keeps a helpful error when the SKU is claimed in the lookup table but no trashed product exists', function () {
    // Arrange
    $product = Product::factory()->simple()->create();

    Cache::put('wc_default_credential', [
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
    ], 300);

    $exporter = $this->mock(Exporter::class);
    $exporter->shouldReceive('initMappingsAndAttribute')->once();
    $exporter->shouldReceive('setMediaExport')->with(true)->once();
    $exporter->shouldReceive('formatData')->once()->andReturn([
        'sku'    => $product->sku,
        'name'   => $product->sku,
        'type'   => 'simple',
        'status' => 'publish',
    ]);

    $connectorService = $this->mock(WooCommerceService::class);
    $connectorService->shouldReceive('requestApiAction')
        ->with('getProductWithSku', [], Mockery::any())
        ->andReturn(['code' => 200]);
    $connectorService->shouldReceive('requestApiAction')
        ->with('addProduct', Mockery::any(), Mockery::any())
        ->once()
        ->andReturn([
            'code'    => 400,
            'message' => "The product with SKU ({$product->sku}) you are trying to insert is already present in the lookup table",
            'data'    => ['status' => 400],
        ]);
    $connectorService->shouldNotReceive('requestApiAction')->with('deleteProduct', Mockery::any(), Mockery::any());

    $mappingRepo = $this->mock(DataTransferMappingRepository::class);
    $mappingRepo->shouldReceive('where')->andReturnSelf();
    $mappingRepo->shouldReceive('get')->andReturn(collect());

    Sentry::shouldReceive('captureException')->once();

    // Act
    ProcessProductsToWooCommerce::dispatchSync(
        ProductBatch::fromProductArray(['sku' => $product->sku, 'parent_id' => null, 'variants' => []])
    );

    // Assert
    $product->refresh();
    expect($product->additional['product_sync_error'])
        ->toContain('zoektabel')
        ->toContain('prullenbak');
});

/**
 * A copied product and its variants start as temporary-sku-*. When one was
 * synced before its SKU was changed, the SKU lookup no longer finds it; the
 * sync has to update the WooCommerce object it synced to last time instead of
 * creating a second one next to it (which stays behind as a duplicate).
 */
function syncRenamedProduct(array $productData, callable $expectRequests): void
{
    Cache::put('wc_default_credential', [
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
    ], 300);

    $exporter = mockInContainer(Exporter::class);
    $exporter->shouldReceive('initMappingsAndAttribute')->once();
    $exporter->shouldReceive('setMediaExport')->with(true)->once();
    $exporter->shouldReceive('formatData')->once()->andReturn($productData);

    $mappingRepo = mockInContainer(DataTransferMappingRepository::class);
    $mappingRepo->shouldReceive('where')->andReturnSelf();
    $mappingRepo->shouldReceive('get')->andReturn(collect());

    $expectRequests(mockInContainer(WooCommerceService::class));

    ProcessProductsToWooCommerce::dispatchSync(
        ProductBatch::fromProductArray(['sku' => $productData['sku'], 'parent_id' => $productData['parent_id'] ?? null, 'variants' => []])
    );
}

function mockInContainer(string $class): MockInterface
{
    $mock = Mockery::mock($class);
    app()->instance($class, $mock);

    return $mock;
}

function recordPreviousSync(int $productId, int $externalId): void
{
    WooCommerceSyncEvent::create([
        'product_id'  => $productId,
        'action'      => 'sync',
        'status'      => WooCommerceSyncEventStatus::Success,
        'external_id' => (string) $externalId,
    ]);
}

it('updates the variation it synced before when the variant SKU changed', function () {
    // Arrange
    $parent = Product::factory()->configurable()->create(['sku' => 'ERG9567']);
    $variant = Product::factory()->simple()->create(['sku' => 'ERG9567.2', 'parent_id' => $parent->id]);
    recordPreviousSync($variant->id, 529448);

    // Act
    syncRenamedProduct(['sku' => 'ERG9567.2', 'parent_id' => $parent->id], function ($wc) {
        $wc->shouldReceive('requestApiAction')->with('getProductWithSku', [], ['sku' => 'ERG9567'])
            ->andReturn([['id' => 529445], 'code' => 200]);
        $wc->shouldReceive('requestApiAction')->with('getVariation', [], ['sku' => 'ERG9567.2', 'product' => 529445])
            ->andReturn(['code' => 200]);
        $wc->shouldReceive('requestApiAction')->with('getVariationById', [], ['product' => 529445, 'variationid' => '529448'])
            ->once()
            ->andReturn(['id' => 529448, 'parent_id' => 529445, 'sku' => 'temporary-sku-743aad', 'code' => 200]);
        $wc->shouldReceive('requestApiAction')
            ->with('updateVariation', Mockery::any(), Mockery::on(fn ($params) => $params['id'] === 529448 && $params['product'] === 529445))
            ->once()
            ->andReturn(['code' => 200, 'id' => 529448]);
        $wc->shouldNotReceive('requestApiAction')->with('addVariation', Mockery::any(), Mockery::any());
    });

    // Assert
    expect(WooCommerceSyncEvent::whereProductId($variant->id)->latest('id')->first())
        ->status->toBe(WooCommerceSyncEventStatus::Success)
        ->external_id->toBe('529448');
});

it('updates the product it synced before when the parent SKU changed', function () {
    // Arrange
    $parent = Product::factory()->configurable()->create(['sku' => 'ERG9567']);
    recordPreviousSync($parent->id, 529445);

    // Act
    syncRenamedProduct(['sku' => 'ERG9567', 'type' => 'variable'], function ($wc) {
        $wc->shouldReceive('requestApiAction')->with('getProductWithSku', [], ['sku' => 'ERG9567'])
            ->andReturn(['code' => 200]);
        $wc->shouldReceive('requestApiAction')->with('getProduct', [], ['id' => '529445'])
            ->once()
            ->andReturn(['id' => 529445, 'parent_id' => 0, 'sku' => 'temporary-sku-5d1c0e', 'status' => 'publish', 'code' => 200]);
        $wc->shouldReceive('requestApiAction')
            ->with('updateProduct', Mockery::any(), Mockery::on(fn ($params) => $params['id'] === 529445))
            ->once()
            ->andReturn(['code' => 200, 'id' => 529445]);
        $wc->shouldNotReceive('requestApiAction')->with('addProduct', Mockery::any(), Mockery::any());
    });

    // Assert
    expect(WooCommerceSyncEvent::whereProductId($parent->id)->latest('id')->first()->external_id)->toBe('529445');
});

it('creates a new variation when the one it synced before now belongs to another variant', function () {
    // Arrange
    $parent = Product::factory()->configurable()->create(['sku' => 'ERG9567']);
    $variant = Product::factory()->simple()->create(['sku' => 'ERG9567.2', 'parent_id' => $parent->id]);
    Product::factory()->simple()->create(['sku' => 'ERG9567.3', 'parent_id' => $parent->id]);
    recordPreviousSync($variant->id, 529448);

    // Act
    syncRenamedProduct(['sku' => 'ERG9567.2', 'parent_id' => $parent->id], function ($wc) {
        $wc->shouldReceive('requestApiAction')->with('getProductWithSku', [], ['sku' => 'ERG9567'])
            ->andReturn([['id' => 529445], 'code' => 200]);
        $wc->shouldReceive('requestApiAction')->with('getVariation', [], ['sku' => 'ERG9567.2', 'product' => 529445])
            ->andReturn(['code' => 200]);
        $wc->shouldReceive('requestApiAction')->with('getVariationById', [], ['product' => 529445, 'variationid' => '529448'])
            ->andReturn(['id' => 529448, 'parent_id' => 529445, 'sku' => 'ERG9567.3', 'code' => 200]);
        $wc->shouldReceive('requestApiAction')->with('addVariation', Mockery::any(), Mockery::any())
            ->once()
            ->andReturn(['code' => 201, 'id' => 529460]);
        $wc->shouldNotReceive('requestApiAction')->with('updateVariation', Mockery::any(), Mockery::any());
    });

    // Assert
    expect(WooCommerceSyncEvent::whereProductId($variant->id)->latest('id')->first()->external_id)->toBe('529460');
});

it('creates a new variation when the one it synced before no longer exists', function () {
    // Arrange
    $parent = Product::factory()->configurable()->create(['sku' => 'ERG9567']);
    $variant = Product::factory()->simple()->create(['sku' => 'ERG9567.2', 'parent_id' => $parent->id]);
    recordPreviousSync($variant->id, 529448);

    // Act
    syncRenamedProduct(['sku' => 'ERG9567.2', 'parent_id' => $parent->id], function ($wc) {
        $wc->shouldReceive('requestApiAction')->with('getProductWithSku', [], ['sku' => 'ERG9567'])
            ->andReturn([['id' => 529445], 'code' => 200]);
        $wc->shouldReceive('requestApiAction')->with('getVariation', [], ['sku' => 'ERG9567.2', 'product' => 529445])
            ->andReturn(['code' => 200]);
        $wc->shouldReceive('requestApiAction')->with('getVariationById', [], ['product' => 529445, 'variationid' => '529448'])
            ->andReturn(['code' => 404, 'message' => 'Ongeldige ID.']);
        $wc->shouldReceive('requestApiAction')->with('addVariation', Mockery::any(), Mockery::any())
            ->once()
            ->andReturn(['code' => 201, 'id' => 529460]);
    });

    // Assert
    expect(WooCommerceSyncEvent::whereProductId($variant->id)->latest('id')->first()->external_id)->toBe('529460');
});
