<?php

use App\Services\Bol\BolProductValidator;
use Webkul\Product\Models\Product;

function bolProduct(array $values = [], ?Product $parent = null): Product
{
    $product = new Product();
    $product->id = 1;
    $product->sku = 'TEST-SKU';
    $product->parent_id = $parent?->id;
    $product->setRelation('parent', $parent);

    $defaults = [
        'common' => [
            'ean'                    => '5414452716061',
            'maat'                   => '80 cm x 150 cm',
            'productnaam'            => 'Test Tapijt',
            'prijs'                  => ['EUR' => 99],
            'afbeelding'             => '11986,12013',
            'afbeelding_zonder_logo' => null,
        ],
    ];

    $product->values = array_replace_recursive($defaults, $values);

    return $product;
}

it('passes a fully valid product', function () {
    $validator = new BolProductValidator();
    $result = $validator->validate(bolProduct());

    expect($result->passed())->toBeTrue()
        ->and($result->normalizedEan)->toBe('5414452716061');
});

it('flags an invalid EAN with a Dutch customer message', function () {
    $validator = new BolProductValidator();
    $result = $validator->validate(bolProduct(['common' => ['ean' => 'abc']]));

    expect($result->failed())->toBeTrue()
        ->and(collect($result->failures)->pluck('code')->all())->toContain('ean_invalid')
        ->and($result->customerSummary())->toContain('EAN-code');
});

it('normalizes a 14-digit EAN', function () {
    $validator = new BolProductValidator();
    $result = $validator->validate(bolProduct(['common' => ['ean' => '05715694000315']]));

    expect($result->passed())->toBeTrue()
        ->and($result->normalizedEan)->toBe('5715694000315');
});

it('blocks Maatwerk', function () {
    $validator = new BolProductValidator();
    $result = $validator->validate(bolProduct(['common' => ['maat' => 'Maatwerk']]));

    expect($result->failed())->toBeTrue()
        ->and(collect($result->failures)->pluck('code')->all())->toContain('maat_unsupported');
});

it('blocks missing maat', function () {
    $validator = new BolProductValidator();
    $result = $validator->validate(bolProduct(['common' => ['maat' => null]]));

    expect($result->failed())->toBeTrue()
        ->and(collect($result->failures)->pluck('code')->all())->toContain('maat_missing');
});

it('blocks missing or zero price', function () {
    $validator = new BolProductValidator();
    $result = $validator->validate(bolProduct(['common' => ['prijs' => ['EUR' => 0]]]));

    expect($result->failed())->toBeTrue()
        ->and(collect($result->failures)->pluck('code')->all())->toContain('price_missing');
});

it('blocks missing images on both product and parent', function () {
    $validator = new BolProductValidator();
    $result = $validator->validate(bolProduct(['common' => ['afbeelding' => null, 'afbeelding_zonder_logo' => null]]));

    expect($result->failed())->toBeTrue()
        ->and(collect($result->failures)->pluck('code')->all())->toContain('images_missing');
});

it('blocks a "Met onderkleed" variant with a Dutch customer message', function () {
    $validator = new BolProductValidator();
    $result = $validator->validate(bolProduct(['common' => ['onderkleed' => 'Met onderkleed']]));

    expect($result->failed())->toBeTrue()
        ->and(collect($result->failures)->pluck('code')->all())->toContain('onderkleed_variant_blocked')
        ->and($result->customerSummary())->toContain('Zonder onderkleed');
});

it('passes a "Zonder onderkleed" variant', function () {
    $validator = new BolProductValidator();
    $result = $validator->validate(bolProduct(['common' => ['onderkleed' => 'Zonder onderkleed']]));

    expect($result->passed())->toBeTrue();
});

it('passes a product without an onderkleed attribute', function () {
    $validator = new BolProductValidator();
    $result = $validator->validate(bolProduct(['common' => ['onderkleed' => null]]));

    expect($result->passed())->toBeTrue();
});

it('accepts images stored as an array', function () {
    $validator = new BolProductValidator();
    $result = $validator->validate(bolProduct(['common' => ['afbeelding' => ['11986', '12013']]]));

    expect($result->passed())->toBeTrue();
});
