<?php

use App\Services\Bol\BolPayloadBuilder;
use Webkul\Product\Models\Product;

function fulfilmentFor(string $deliveryCode): array
{
    $parent = new Product();
    $parent->id = 1;
    $parent->values = ['common' => []];

    $product = new Product();
    $product->id = 2;
    $product->sku = 'TEST';
    $product->parent_id = 1;
    $product->setRelation('parent', $parent);
    $product->values = ['common' => [
        'ean'         => '5414452716061',
        'productnaam' => 'X',
        'maat'        => '80 cm x 150 cm',
        'prijs'       => ['EUR' => 99],
    ]];

    return (new BolPayloadBuilder())->offer($product, $deliveryCode)['fulfilment'];
}

it('maps a "4-8d" delivery code to v11 deliveryPromise 4-8', function () {
    expect(fulfilmentFor('4-8d'))->toBe([
        'method'          => 'FBR',
        'schedule'        => 'MY_DELIVERY_PROMISE',
        'deliveryPromise' => ['minimumDaysToCustomer' => 4, 'maximumDaysToCustomer' => 8],
    ]);
});

it('maps "1-2d" to deliveryPromise 1-2', function () {
    expect(fulfilmentFor('1-2d')['deliveryPromise'])->toBe([
        'minimumDaysToCustomer' => 1,
        'maximumDaysToCustomer' => 2,
    ]);
});

it('maps "24uurs-17" to next-day delivery with 17:00 order cutoff', function () {
    expect(fulfilmentFor('24uurs-17'))->toBe([
        'method'          => 'FBR',
        'schedule'        => 'MY_DELIVERY_PROMISE',
        'deliveryPromise' => [
            'minimumDaysToCustomer' => 0,
            'maximumDaysToCustomer' => 1,
            'ultimateOrderTime'     => '17:00',
        ],
    ]);
});

it('maps "VVB" to SHIPPING_VIA_BOL with no deliveryPromise', function () {
    expect(fulfilmentFor('VVB'))->toBe([
        'method'   => 'FBR',
        'schedule' => 'SHIPPING_VIA_BOL',
    ]);
});

it('falls back to 1-8 day promise for unknown / legacy codes', function () {
    expect(fulfilmentFor('MijnLeverbelofte')['deliveryPromise'])->toBe([
        'minimumDaysToCustomer' => 1,
        'maximumDaysToCustomer' => 8,
    ]);
});
