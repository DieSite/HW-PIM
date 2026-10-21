<?php

use App\Enums\WooCommerceSyncEventStatus;
use App\Exceptions\WoocommerceTimeoutException;
use App\Models\WooCommerceSyncEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Webkul\Core\Models\CoreConfig;
use Webkul\Product\Models\Product;
use Webkul\WooCommerce\DTO\ProductBatch;
use Webkul\WooCommerce\Helpers\Exporters\Product\Exporter;
use Webkul\WooCommerce\Listeners\ProcessProductsToWooCommerce;
use Webkul\WooCommerce\Models\DataTransferMapping;
use Webkul\WooCommerce\Services\WooCommerceService;

const DISABLED_VARIANT_SHOP_URL = 'https://shop.test';

/**
 * @return array<string, mixed>
 */
function disabledVariantCredential(): array
{
    return [
        'id'      => 1,
        'shopUrl' => DISABLED_VARIANT_SHOP_URL,
        'extras'  => [
            'settings'      => ['regular_price' => 'price'],
            'quicksettings' => [
                'auto_sync'      => 1,
                'quick_channel'  => 'default',
                'quick_locale'   => 'en_US',
                'quick_currency' => 'EUR',
            ],
        ],
    ];
}

/**
 * A parent with one variant per given status.
 *
 * @param  array<string, int>  $variantStatuses  Variant SKU => status
 */
function rugWithVariantStatuses(array $variantStatuses): Product
{
    $parent = Product::factory()->configurable()->create(['sku' => 'ERG0700', 'values' => ['common' => ['sku' => 'ERG0700']]]);

    foreach ($variantStatuses as $sku => $status) {
        Product::factory()->simple()->create([
            'sku'       => $sku,
            'parent_id' => $parent->id,
            'status'    => $status,
            'values'    => ['common' => ['sku' => $sku, 'maat' => str_ends_with($sku, '.1') ? '160 cm x 230 cm' : '200 cm x 290 cm', 'onderkleed' => 'Zonder onderkleed', 'price' => ['EUR' => '199.00']]],
        ]);
    }

    return $parent;
}

/**
 * Run the sync job for a product against a mocked WooCommerce API, the way
 * SerializedProcessProductsToWooCommerce runs it.
 */
function syncAgainstShop(Product $product, callable $expectRequests): void
{
    Cache::put('wc_default_credential', disabledVariantCredential(), 300);

    $shop = Mockery::mock(WooCommerceService::class);
    $expectRequests($shop);
    app()->instance(WooCommerceService::class, $shop);

    $product = Product::query()->with('parent')->find($product->id);

    ProcessProductsToWooCommerce::dispatchSync(ProductBatch::fromProductArray($product->toArray()));
}

/**
 * Never formats the product: a disabled variant is only looked up and deleted.
 */
function expectNoFormatting(): void
{
    $exporter = Mockery::mock(Exporter::class)->makePartial();
    $exporter->shouldReceive('initMappingsAndAttribute');
    $exporter->shouldNotReceive('formatData');
    app()->instance(Exporter::class, $exporter);
}

function latestSyncEvent(Product $product): ?WooCommerceSyncEvent
{
    return WooCommerceSyncEvent::query()->where('product_id', $product->id)->latest('id')->first();
}

beforeEach(function () {
    Storage::fake('private');
    Storage::fake(config('filesystems.default'));
});

it('deletes the WooCommerce variation of a disabled variant', function () {
    // Arrange
    rugWithVariantStatuses(['ERG0700.1' => 0]);
    $variant = Product::whereSku('ERG0700.1')->first();
    DataTransferMapping::query()->create(['entityType' => 'product', 'code' => 'ERG0700.1', 'externalId' => '501', 'apiUrl' => DISABLED_VARIANT_SHOP_URL]);
    expectNoFormatting();

    // Act
    syncAgainstShop($variant, function (MockInterface $shop) {
        $shop->shouldReceive('requestApiAction')->with('getProductWithSku', [], ['sku' => 'ERG0700.1'])
            ->once()
            ->andReturn([['id' => 501, 'type' => 'variation', 'parent_id' => 500, 'sku' => 'ERG0700.1'], 'code' => 200]);
        $shop->shouldReceive('requestApiAction')
            ->with('deleteProductVariant', [], ['credential' => 1, 'product' => 500, 'id' => 501, 'force' => 'true'])
            ->once()
            ->andReturn(['id' => 501, 'code' => 200]);
    });

    // Assert
    expect(latestSyncEvent($variant))
        ->status->toBe(WooCommerceSyncEventStatus::Success)
        ->customer_message->toBe('Inactieve variant verwijderd uit WooCommerce.')
        ->and(DataTransferMapping::query()->where('code', 'ERG0700.1')->exists())->toBeFalse();
});

it('leaves the shop alone when a disabled variant is not in WooCommerce', function () {
    // Arrange
    rugWithVariantStatuses(['ERG0700.1' => 0]);
    $variant = Product::whereSku('ERG0700.1')->first();
    expectNoFormatting();

    // Act
    syncAgainstShop($variant, function (MockInterface $shop) {
        $shop->shouldReceive('requestApiAction')->with('getProductWithSku', [], ['sku' => 'ERG0700.1'])
            ->once()
            ->andReturn(['code' => 200]);
        $shop->shouldNotReceive('requestApiAction')->with('deleteProductVariant', Mockery::any(), Mockery::any());
    });

    // Assert
    expect(latestSyncEvent($variant))
        ->status->toBe(WooCommerceSyncEventStatus::Success)
        ->customer_message->toBe('Inactieve variant staat niet in WooCommerce.');
});

it('never deletes a simple product that happens to have the SKU of a disabled variant', function () {
    // Arrange
    rugWithVariantStatuses(['ERG0700.1' => 0]);
    expectNoFormatting();

    // Act
    syncAgainstShop(Product::whereSku('ERG0700.1')->first(), function (MockInterface $shop) {
        $shop->shouldReceive('requestApiAction')->with('getProductWithSku', [], ['sku' => 'ERG0700.1'])
            ->once()
            ->andReturn([['id' => 777, 'type' => 'simple', 'parent_id' => 0, 'sku' => 'ERG0700.1'], 'code' => 200]);
        $shop->shouldNotReceive('requestApiAction')->with('deleteProductVariant', Mockery::any(), Mockery::any());
        $shop->shouldNotReceive('requestApiAction')->with('deleteProduct', Mockery::any(), Mockery::any());
    });
});

it('retries instead of concluding the variation is gone when the lookup times out', function () {
    // Arrange
    rugWithVariantStatuses(['ERG0700.1' => 0]);
    $variant = Product::whereSku('ERG0700.1')->first();
    expectNoFormatting();

    // Act
    $sync = fn () => syncAgainstShop($variant, function (MockInterface $shop) {
        $shop->shouldReceive('requestApiAction')->with('getProductWithSku', [], ['sku' => 'ERG0700.1'])
            ->andReturn(['code' => 0, 'error' => 'Operation timed out']);
        $shop->shouldNotReceive('requestApiAction')->with('deleteProductVariant', Mockery::any(), Mockery::any());
    });

    // Assert
    expect($sync)->toThrow(WoocommerceTimeoutException::class)
        ->and(latestSyncEvent($variant)->status)->toBe(WooCommerceSyncEventStatus::Failed);
});

it('fails the sync when WooCommerce refuses to delete the variation', function () {
    // Arrange
    rugWithVariantStatuses(['ERG0700.1' => 0]);
    $variant = Product::whereSku('ERG0700.1')->first();
    expectNoFormatting();

    // Act
    $sync = fn () => syncAgainstShop($variant, function (MockInterface $shop) {
        $shop->shouldReceive('requestApiAction')->with('getProductWithSku', [], ['sku' => 'ERG0700.1'])
            ->andReturn([['id' => 501, 'type' => 'variation', 'parent_id' => 500, 'sku' => 'ERG0700.1'], 'code' => 200]);
        $shop->shouldReceive('requestApiAction')->with('deleteProductVariant', Mockery::any(), Mockery::any())
            ->andReturn(['code' => 403, 'message' => 'Sorry, je kunt dit niet verwijderen.']);
    });

    // Assert
    expect($sync)->toThrow(Exception::class, '403')
        ->and(Product::whereSku('ERG0700.1')->first()->additional['product_sync_error'] ?? null)->toContain('403');
});

it('keeps updating an enabled variant', function () {
    // Arrange
    rugWithVariantStatuses(['ERG0700.1' => 1]);
    $exporter = Mockery::mock(Exporter::class)->makePartial();
    $exporter->shouldReceive('initMappingsAndAttribute');
    $exporter->shouldReceive('formatData')->once()->andReturnUsing(fn (ProductBatch $batch) => ['sku' => $batch->sku, 'parent_id' => $batch->parentId]);
    app()->instance(Exporter::class, $exporter);

    // Act
    syncAgainstShop(Product::whereSku('ERG0700.1')->first(), function (MockInterface $shop) {
        $shop->shouldReceive('requestApiAction')->with('getProductWithSku', [], ['sku' => 'ERG0700'])
            ->andReturn([['id' => 500], 'code' => 200]);
        $shop->shouldReceive('requestApiAction')->with('getVariation', [], ['sku' => 'ERG0700.1', 'product' => 500])
            ->andReturn([['id' => 501], 'code' => 200]);
        $shop->shouldReceive('requestApiAction')->with('updateVariation', Mockery::any(), Mockery::any())
            ->once()
            ->andReturn(['id' => 501, 'sku' => 'ERG0700.1', 'code' => 200]);
        $shop->shouldNotReceive('requestApiAction')->with('deleteProductVariant', Mockery::any(), Mockery::any());
    });
});

it('offers only the sizes of enabled variants on the parent', function () {
    // Arrange
    $parent = rugWithVariantStatuses(['ERG0700.1' => 0, 'ERG0700.2' => 1]);

    foreach (['maat' => 10, 'onderkleed' => 12, 'maatgroep' => 13] as $code => $externalId) {
        DataTransferMapping::query()->create(['entityType' => 'attribute', 'code' => $code, 'externalId' => $externalId, 'apiUrl' => DISABLED_VARIANT_SHOP_URL]);
    }

    Storage::disk(config('filesystems.default'))->put('configuration/geen-afbeelding.png', 'png');
    CoreConfig::query()->create(['code' => 'product_images.settings.general.default_image', 'value' => 'configuration/geen-afbeelding.png']);

    $exporter = app(Exporter::class);
    $exporter->credential = disabledVariantCredential();
    $exporter->initMappingsAndAttribute();
    $exporter->locale = 'en_US';
    $exporter->channel = 'default';
    $exporter->currency = 'EUR';

    // Act
    $formatted = $exporter->formatData(ProductBatch::fromProductArray(
        Product::query()->with('variants')->find($parent->id)->toArray()
    ));

    // Assert
    expect(collect($formatted['attributes'])->firstWhere('id', 10)['options'])->toBe(['200 cm x 290 cm'])
        ->and($formatted['default_attributes'][0])->toEqual(['id' => 10, 'option' => '200 cm x 290 cm']);
});
