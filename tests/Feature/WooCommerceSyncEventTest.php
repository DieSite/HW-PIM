<?php

use App\Enums\WooCommerceSyncEventStatus;
use App\Models\Product;
use App\Models\WooCommerceSyncEvent;
use App\Services\ProductService;
use App\Services\WooCommerce\WooCommerceSyncEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\WooCommerce\Listeners\SerializedProcessProductsToWooCommerce;

function makeWcSyncProduct(string $sku = 'WCSYNC-V1'): Product
{
    $familyId = DB::table('attribute_families')->value('id')
        ?? DB::table('attribute_families')->insertGetId(['code' => 'fam_'.uniqid(), 'status' => 1]);

    $product = new Product();
    $product->attribute_family_id = $familyId;
    $product->sku = $sku;
    $product->type = 'simple';
    $product->status = 1;
    $product->values = ['common' => []];
    $product->save();

    return $product;
}

afterEach(function () {
    Product::where('sku', 'like', 'WCSYNC-%')->delete();
});

it('records a WooCommerce sync event and exposes it through the product relation', function () {
    $product = makeWcSyncProduct();

    $recorder = app(WooCommerceSyncEventRecorder::class);

    $started = $recorder->record(
        $product,
        WooCommerceSyncEventStatus::Started,
        'sync',
        'Synchronisatie met WooCommerce gestart.',
        'Synchronisatie met WooCommerce gestart.'
    );

    $success = $recorder->record(
        $product,
        WooCommerceSyncEventStatus::Success,
        'sync',
        'Bijgewerkt in WooCommerce.',
        'Bijgewerkt in WooCommerce.',
        '4242'
    );

    expect(WooCommerceSyncEvent::where('product_id', $product->id)->count())->toBe(2);
    expect($started->status)->toBe(WooCommerceSyncEventStatus::Started);
    expect($success->external_id)->toBe('4242');

    $events = $product->fresh()->wooCommerceSyncEvents;

    expect($events)->toHaveCount(2);
    expect($events->first()->id)->toBe($success->id)
        ->and($events->first()->status)->toBe(WooCommerceSyncEventStatus::Success);
});

it('re-dispatches a WooCommerce sync chain when retrying a parent product', function () {
    Queue::fake();

    $familyId = DB::table('attribute_families')->value('id')
        ?? DB::table('attribute_families')->insertGetId(['code' => 'fam_'.uniqid(), 'status' => 1]);

    $parent = new Product();
    $parent->attribute_family_id = $familyId;
    $parent->sku = 'WCSYNC-PARENT';
    $parent->type = 'configurable';
    $parent->status = 1;
    $parent->values = ['common' => []];
    $parent->save();

    $variant = new Product();
    $variant->attribute_family_id = $familyId;
    $variant->parent_id = $parent->id;
    $variant->sku = 'WCSYNC-CHILD';
    $variant->type = 'simple';
    $variant->status = 1;
    $variant->values = ['common' => []];
    $variant->save();

    app(ProductService::class)->triggerWCSyncForParent($parent->fresh());

    Queue::assertPushed(SerializedProcessProductsToWooCommerce::class);
});
