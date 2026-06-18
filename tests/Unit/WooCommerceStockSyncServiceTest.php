<?php

use App\Services\WooCommerceStockSyncService;

it('sums the three stock sources and marks instock when positive', function () {
    $update = WooCommerceStockSyncService::stockUpdateFromValues('ABC-123', [
        'common' => [
            'voorraad_eurogros'             => 5,
            'voorraad_5_korting_handmatig'  => 2,
            'voorraad_hw_5_korting'         => 1,
        ],
    ]);

    expect($update)->toBe([
        'sku'            => 'ABC-123',
        'stock_quantity' => 8,
        'stock_status'   => 'instock',
    ]);
});

it('marks onbackorder when the total is zero', function () {
    $update = WooCommerceStockSyncService::stockUpdateFromValues('ABC-124', [
        'common' => ['voorraad_eurogros' => 0],
    ]);

    expect($update['stock_quantity'])->toBe(0)
        ->and($update['stock_status'])->toBe('onbackorder');
});

it('treats missing stock keys as zero', function () {
    $update = WooCommerceStockSyncService::stockUpdateFromValues('ABC-125', ['common' => []]);

    expect($update['stock_quantity'])->toBe(0)
        ->and($update['stock_status'])->toBe('onbackorder');
});

it('coerces string stock values to integers', function () {
    $update = WooCommerceStockSyncService::stockUpdateFromValues('ABC-126', [
        'common' => ['voorraad_eurogros' => '12'],
    ]);

    expect($update['stock_quantity'])->toBe(12);
});

it('builds the stock endpoint from the shop url', function () {
    expect(WooCommerceStockSyncService::resolveEndpoint('https://www.huis-en-wonen.nl'))
        ->toBe('https://www.huis-en-wonen.nl/wp-json/diesite/v1/stock')
        ->and(WooCommerceStockSyncService::resolveEndpoint('https://www.huis-en-wonen.nl/'))
        ->toBe('https://www.huis-en-wonen.nl/wp-json/diesite/v1/stock')
        ->and(WooCommerceStockSyncService::resolveEndpoint('https://www.huis-en-wonen.nl/wp-admin'))
        ->toBe('https://www.huis-en-wonen.nl/wp-json/diesite/v1/stock');
});
