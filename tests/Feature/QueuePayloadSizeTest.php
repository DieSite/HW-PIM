<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Product\Jobs\ElasticSearch\UpdateCreateIndex;
use Webkul\Product\Listeners\Product as ProductListener;
use Webkul\Product\Models\Product;
use Webkul\WooCommerce\Helpers\Webhook\ProcessWooCommerceWebhook;

it('does not queue an elasticsearch reindex when elastic search mode is off', function () {
    Queue::fake();

    app(ProductListener::class)->afterUpdate(new Product(['type' => 'simple']));

    Queue::assertNotPushed(UpdateCreateIndex::class);
});

it('queues an elasticsearch reindex when elastic search mode is on', function () {
    Queue::fake();

    DB::table('core_config')->insert([
        'code'  => 'catalog.products.storefront.search_mode',
        'value' => 'elastic',
    ]);

    $product = new Product(['type' => 'simple']);
    $product->id = 123;

    app(ProductListener::class)->afterUpdate($product);

    Queue::assertPushed(UpdateCreateIndex::class);
});

it('queues only the line item fields of a woocommerce order webhook', function () {
    Queue::fake();

    $this->postJson('woocommerce/callback', [
        'id'         => 991,
        'billing'    => ['first_name' => 'Jan', 'email' => 'jan@example.com', 'address_1' => str_repeat('x', 5_000)],
        'meta_data'  => array_fill(0, 50, ['key' => 'k', 'value' => str_repeat('y', 200)]),
        'line_items' => [
            ['product_id' => 5, 'sku' => 'ABC', 'quantity' => 2, 'name' => 'Kleed', 'meta_data' => [['key' => 'k', 'value' => 'v']]],
        ],
    ])->assertOk();

    Queue::assertPushed(ProcessWooCommerceWebhook::class, function (ProcessWooCommerceWebhook $job): bool {
        $data = (fn (): array => $this->webhookData)->call($job);

        return $data === ['line_items' => [['product_id' => 5, 'sku' => 'ABC', 'quantity' => 2]]]
            && strlen(serialize($job)) < 1_000;
    });
});
