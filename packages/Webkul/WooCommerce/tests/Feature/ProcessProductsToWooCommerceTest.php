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

    // Act — the job re-throws after saving the error
    expect(fn () => ProcessProductsToWooCommerce::dispatchSync(
        ProductBatch::fromProductArray(['sku' => $product->sku, 'parent_id' => null, 'variants' => []])
    ))->toThrow(\Exception::class);

    // Assert
    $product->refresh();
    expect($product->additional['product_sync_error'])
        ->toContain('variations');
});