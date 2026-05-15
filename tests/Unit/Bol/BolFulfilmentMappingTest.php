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

it('maps a "4-8d" delivery code to BOL_DELIVERY_PROMISE preset 4-8', function () {
    expect(fulfilmentFor('4-8d'))->toBe([
        'method'          => 'FBR',
        'schedule'        => 'BOL_DELIVERY_PROMISE',
        'deliveryPromise' => ['minimumDaysToCustomer' => 4, 'maximumDaysToCustomer' => 8],
    ]);
});

it('maps "1-2d" / "2-3d" / "3-5d" to BOL_DELIVERY_PROMISE presets', function () {
    expect(fulfilmentFor('1-2d'))->toMatchArray(['schedule' => 'BOL_DELIVERY_PROMISE', 'deliveryPromise' => ['minimumDaysToCustomer' => 1, 'maximumDaysToCustomer' => 2]])
        ->and(fulfilmentFor('2-3d'))->toMatchArray(['schedule' => 'BOL_DELIVERY_PROMISE', 'deliveryPromise' => ['minimumDaysToCustomer' => 2, 'maximumDaysToCustomer' => 3]])
        ->and(fulfilmentFor('3-5d'))->toMatchArray(['schedule' => 'BOL_DELIVERY_PROMISE', 'deliveryPromise' => ['minimumDaysToCustomer' => 3, 'maximumDaysToCustomer' => 5]]);
});

it('maps "24uurs-17" to BOL_DELIVERY_PROMISE next-day with 17:00 cutoff', function () {
    expect(fulfilmentFor('24uurs-17'))->toBe([
        'method'          => 'FBR',
        'schedule'        => 'BOL_DELIVERY_PROMISE',
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

it('maps "MijnLeverbelofte" to MY_DELIVERY_PROMISE (retailer-defined preset)', function () {
    expect(fulfilmentFor('MijnLeverbelofte'))->toBe([
        'method'   => 'FBR',
        'schedule' => 'MY_DELIVERY_PROMISE',
    ]);
});

it('falls back to BOL_DELIVERY_PROMISE 4-8 for unknown codes', function () {
    expect(fulfilmentFor('garbage'))->toBe([
        'method'          => 'FBR',
        'schedule'        => 'BOL_DELIVERY_PROMISE',
        'deliveryPromise' => ['minimumDaysToCustomer' => 4, 'maximumDaysToCustomer' => 8],
    ]);
});
