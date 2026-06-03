<?php

use App\Services\WooCommerceStockSyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::put('wc_default_credential', [
        'shopUrl'        => 'https://www.huis-en-wonen.nl',
        'consumerKey'    => 'ck_test',
        'consumerSecret' => 'cs_test',
    ], 300);
});

it('posts updates to the stock endpoint with basic auth', function () {
    Http::fake([
        '*/wp-json/diesite/v1/stock' => Http::response(['updated' => 1, 'unchanged' => 0, 'failed' => 0, 'items' => []], 200),
    ]);

    $updates = [
        ['sku' => 'ABC-123', 'stock_quantity' => 5, 'stock_status' => 'instock'],
    ];

    app(WooCommerceStockSyncService::class)->pushUpdates($updates);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://www.huis-en-wonen.nl/wp-json/diesite/v1/stock'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('ck_test:cs_test'))
            && $request['updates'] === [['sku' => 'ABC-123', 'stock_quantity' => 5, 'stock_status' => 'instock']];
    });
});

it('chunks updates into batches of at most 1000', function () {
    Http::fake([
        '*/wp-json/diesite/v1/stock' => Http::response(['updated' => 1, 'failed' => 0], 200),
    ]);

    $updates = collect(range(1, 1500))
        ->map(fn (int $i) => ['sku' => "SKU-$i", 'stock_quantity' => $i, 'stock_status' => 'instock'])
        ->all();

    app(WooCommerceStockSyncService::class)->pushUpdates($updates);

    Http::assertSentCount(2);

    Http::assertSent(fn (Request $request) => count($request['updates']) === 1000);
    Http::assertSent(fn (Request $request) => count($request['updates']) === 500);
});

it('skips updates without a sku', function () {
    Http::fake();

    app(WooCommerceStockSyncService::class)->pushUpdates([
        ['sku' => null, 'stock_quantity' => 5, 'stock_status' => 'instock'],
    ]);

    Http::assertNothingSent();
});

it('does not send when no default credential is configured', function () {
    Cache::forget('wc_default_credential');
    Cache::put('wc_default_credential', ['shopUrl' => '', 'consumerKey' => '', 'consumerSecret' => ''], 300);

    Http::fake();

    app(WooCommerceStockSyncService::class)->pushUpdates([
        ['sku' => 'ABC-123', 'stock_quantity' => 5, 'stock_status' => 'instock'],
    ]);

    Http::assertNothingSent();
});

it('retries on a 5xx response and succeeds', function () {
    Http::fakeSequence('*/wp-json/diesite/v1/stock')
        ->push(['message' => 'server error'], 503)
        ->push(['updated' => 1, 'failed' => 0], 200);

    app(WooCommerceStockSyncService::class)->pushUpdates([
        ['sku' => 'ABC-123', 'stock_quantity' => 5, 'stock_status' => 'instock'],
    ]);

    Http::assertSentCount(2);
});
