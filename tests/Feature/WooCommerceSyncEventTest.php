<?php

use App\Enums\WooCommerceSyncEventStatus;
use App\Models\Product;
use App\Models\WooCommerceSyncEvent;
use App\Services\ProductService;
use App\Services\WooCommerce\WooCommerceSyncEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\User\Tests\Concerns\UserAssertions;
use Webkul\WooCommerce\Listeners\SerializedProcessProductsToWooCommerce;

uses(UserAssertions::class);

function makeWcSyncProduct(string $sku = 'WCSYNC-V1', string $type = 'simple', ?int $parentId = null): Product
{
    $familyId = DB::table('attribute_families')->value('id')
        ?? DB::table('attribute_families')->insertGetId(['code' => 'fam_'.uniqid(), 'status' => 1]);

    $product = new Product();
    $product->attribute_family_id = $familyId;
    $product->parent_id = $parentId;
    $product->sku = $sku;
    $product->type = $type;
    $product->status = 1;
    $product->values = ['common' => []];
    $product->save();

    return $product;
}

/**
 * @return array{0: Product, 1: Product}
 */
function makeWcSyncParentWithVariant(): array
{
    $parent = makeWcSyncProduct('WCSYNC-PARENT', 'configurable');
    $variant = makeWcSyncProduct('WCSYNC-CHILD', 'simple', $parent->id);

    return [$parent->fresh(), $variant];
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

    [$parent] = makeWcSyncParentWithVariant();

    app(ProductService::class)->triggerWCSyncForParent($parent);

    Queue::assertPushed(SerializedProcessProductsToWooCommerce::class);
});

it('records a queued event for the parent and every variant at dispatch time', function () {
    Queue::fake();

    [$parent, $variant] = makeWcSyncParentWithVariant();

    app(ProductService::class)->triggerWCSyncForParent($parent);

    foreach ([$parent, $variant] as $product) {
        $events = $product->fresh()->wooCommerceSyncEvents;

        expect($events)->toHaveCount(1)
            ->and($events->first()->status)->toBe(WooCommerceSyncEventStatus::Queued)
            ->and($events->first()->customer_message)->toBe('In wachtrij geplaatst voor synchronisatie met WooCommerce.');
    }
});

it('records a failed event instead of a queued one when a parent has no variants', function () {
    Queue::fake();

    $parent = makeWcSyncProduct('WCSYNC-LONELY', 'configurable');

    app(ProductService::class)->triggerWCSyncForParent($parent);

    $events = $parent->fresh()->wooCommerceSyncEvents;

    expect($events)->toHaveCount(1)
        ->and($events->first()->status)->toBe(WooCommerceSyncEventStatus::Failed)
        ->and($events->first()->message)->toContain('has no variants');

    Queue::assertNothingPushed();
});

it('serves the timeline fragment showing the queued state', function () {
    $this->loginAsAdmin();

    $product = makeWcSyncProduct('WCSYNC-FRAGMENT');
    app(WooCommerceSyncEventRecorder::class)->queued($product);

    $response = $this->get(route('admin.custom.wooCommerce.product.timeline', $product->id));

    $response->assertOk();

    $body = $response->getContent();

    expect($body)->toContain('WooCommerce synchronisatiestatus')
        ->and($body)->toContain('In wachtrij')
        ->and($body)->toContain('data-state="queued"')
        ->and($body)->not->toContain('__hwWcTimelinePollerBooted');
});

it('ships the poller with the panel on the product edit page', function () {
    $this->loginAsAdmin();

    $product = makeWcSyncProduct('WCSYNC-PANEL');
    app(WooCommerceSyncEventRecorder::class)->queued($product);

    $response = $this->get(route('admin.catalog.products.edit', $product->id));

    $response->assertOk();

    $body = $response->getContent();

    expect($body)->toContain('WooCommerce synchronisatiestatus')
        ->and($body)->toContain('In wachtrij')
        ->and($body)->toContain('__hwWcTimelinePollerBooted');
});
