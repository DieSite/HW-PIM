<?php

use App\Jobs\SyncCategoryToWooCommerceJob;
use App\Services\WooCommerce\WooCommerceCategorySync;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Webkul\Admin\Listeners\ProductStoredListener;
use Webkul\Category\Models\Category;
use Webkul\Product\Models\Product;
use Webkul\WooCommerce\Listeners\DeleteProductFromWooCommerce;
use Webkul\WooCommerce\Models\DataTransferMapping;
use Webkul\WooCommerce\Services\WooCommerceService;

const STRUCTURE_SHOP = 'https://shop.example/';

function structureCredential(): array
{
    return ['id' => 7, 'shopUrl' => STRUCTURE_SHOP, 'extras' => ['quicksettings' => ['auto_sync' => '1']]];
}

it('creates an unmapped category in WooCommerce and maps it', function () {
    $category = Category::query()->create(['code' => 'Louis De Poortere']);

    $connector = $this->mock(WooCommerceService::class);
    $connector->shouldReceive('getCredentialForQuickExport')->andReturn(structureCredential());
    $connector->shouldReceive('requestApiAction')->with('getCategory', [], ['credential' => 7, 'slug' => 'louis-de-poortere'])->once()->andReturn([]);
    $connector->shouldReceive('requestApiAction')->with('addCategory', ['name' => 'Louis De Poortere', 'slug' => 'louis-de-poortere'], ['credential' => 7])->once()->andReturn(['id' => 555]);

    $sync = app(WooCommerceCategorySync::class);

    expect($sync->ensure($category))->toBe(555)
        ->and(DataTransferMapping::query()->where(['entityType' => 'category', 'code' => 'Louis De Poortere', 'apiUrl' => STRUCTURE_SHOP])->value('externalId'))->toBe('555')
        ->and($sync->ensure($category))->toBe(555);
});

it('adopts a shop category with the same slug instead of creating a duplicate', function () {
    $category = Category::query()->create(['code' => 'Brinker']);

    $connector = $this->mock(WooCommerceService::class);
    $connector->shouldReceive('getCredentialForQuickExport')->andReturn(structureCredential());
    $connector->shouldReceive('requestApiAction')->with('getCategory', [], ['credential' => 7, 'slug' => 'brinker'])->once()->andReturn([['id' => 98, 'slug' => 'brinker']]);
    $connector->shouldNotReceive('requestApiAction')->with('addCategory', Mockery::any(), Mockery::any());

    expect(app(WooCommerceCategorySync::class)->ensure($category))->toBe(98);
});

it('fails loudly when WooCommerce does not create the category', function () {
    $category = Category::query()->create(['code' => 'Brinker']);

    $connector = $this->mock(WooCommerceService::class);
    $connector->shouldReceive('getCredentialForQuickExport')->andReturn(structureCredential());
    $connector->shouldReceive('requestApiAction')->with('getCategory', Mockery::any(), Mockery::any())->andReturn([]);
    $connector->shouldReceive('requestApiAction')->with('addCategory', Mockery::any(), Mockery::any())->andReturn(['code' => 'rest_forbidden', 'message' => 'nope']);

    expect(fn () => app(WooCommerceCategorySync::class)->ensure($category))->toThrow(RuntimeException::class, 'did not create category Brinker');

    expect(DataTransferMapping::query()->where('code', 'Brinker')->exists())->toBeFalse();
});

it('does nothing without a shop credential', function () {
    $connector = $this->mock(WooCommerceService::class);
    $connector->shouldReceive('getCredentialForQuickExport')->andReturn(null);
    $connector->shouldNotReceive('requestApiAction');

    expect(app(WooCommerceCategorySync::class)->ensure(Category::query()->create(['code' => 'Brinker'])))->toBeNull();
});

it('queues a category saved in the admin for WooCommerce', function () {
    Queue::fake();

    $category = Category::query()->create(['code' => 'Brinker']);
    Event::dispatch('catalog.category.create.after', $category);

    Queue::assertPushed(SyncCategoryToWooCommerceJob::class, fn (SyncCategoryToWooCommerceJob $job): bool => $job->categoryId === $category->id);
});

it('deletes a variation through the variations endpoint and a parent through the products endpoint', function () {
    $connector = $this->mock(WooCommerceService::class);
    $connector->shouldReceive('getCredentialForQuickExport')->andReturn(structureCredential());
    $connector->shouldReceive('requestApiAction')->with('getProductWithSku', [], ['sku' => 'RUG.1'])->andReturn([['id' => 12, 'type' => 'variation', 'parent_id' => 11]]);
    $connector->shouldReceive('requestApiAction')->with('getProductWithSku', [], ['sku' => 'RUG'])->andReturn([['id' => 11, 'type' => 'variable', 'parent_id' => 0]]);
    $connector->shouldReceive('requestApiAction')->with('deleteProductVariant', [], ['credential' => 7, 'id' => 12, 'product' => 11, 'force' => 'true'])->once()->andReturn(['id' => 12]);
    $connector->shouldReceive('requestApiAction')->with('deleteProduct', [], ['credential' => 7, 'id' => 11])->once()->andReturn(['id' => 11]);

    (new DeleteProductFromWooCommerce('RUG.1'))->handle();
    (new DeleteProductFromWooCommerce('RUG'))->handle();
});

it('fails the delete job when WooCommerce refuses, so it is retried and reported', function () {
    $connector = $this->mock(WooCommerceService::class);
    $connector->shouldReceive('getCredentialForQuickExport')->andReturn(structureCredential());
    $connector->shouldReceive('requestApiAction')->with('getProductWithSku', Mockery::any(), Mockery::any())->andReturn([['id' => 12, 'type' => 'variation', 'parent_id' => 11]]);
    $connector->shouldReceive('requestApiAction')->with('deleteProductVariant', Mockery::any(), Mockery::any())->andReturn(['code' => 'woocommerce_rest_invalid_product_variation_id']);

    expect(fn () => (new DeleteProductFromWooCommerce('RUG.1'))->handle())->toThrow(RuntimeException::class, 'Deleting sku RUG.1');
});

it('gives a new product an SEO title in meta_titel and leaves the description alone', function () {
    $product = Product::factory()->simple()->create();
    $product->values = ['common' => ['sku' => $product->sku, 'productnaam' => 'Antiquarian Kilim 9115', 'merk' => 'Eurogros']];
    $product->save();

    (new ProductStoredListener())->fillMetaValues($product);

    expect($product->fresh()->values['common'])
        ->meta_titel->toBe('Vloerkleed Antiquarian Kilim 9115 van Eurogros bij Huis & Wonen')
        ->not->toHaveKeys(['meta_title', 'meta_description', 'meta_beschrijving']);

    $own = Product::factory()->simple()->create();
    $own->values = ['common' => ['sku' => $own->sku, 'productnaam' => 'X', 'merk' => 'Y', 'meta_titel' => 'Eigen titel']];
    $own->save();

    (new ProductStoredListener())->fillMetaValues($own);

    expect($own->fresh()->values['common']['meta_titel'])->toBe('Eigen titel');
});
