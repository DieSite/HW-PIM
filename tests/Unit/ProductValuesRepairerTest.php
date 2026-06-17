<?php

use App\Services\ProductValuesRepairer;

it('leaves a healthy single-encoded object untouched', function () {
    $healthy = json_encode(['common' => ['ean' => '5414452436013', 'maat' => '240 cm x 340 cm']]);

    expect(ProductValuesRepairer::fix($healthy))->toBeNull();
});

it('repairs a double-encoded values column', function () {
    $object = ['common' => ['ean' => '5414452436013', 'productnaam' => 'Malaga 5151']];
    $double = json_encode(json_encode($object)); // the bug: a JSON string of the JSON object

    $repaired = ProductValuesRepairer::fix($double);

    expect($repaired)->not->toBeNull()
        ->and(json_decode($repaired, true))->toBe($object);
});

it('repairs a triple-encoded values column', function () {
    $object = ['common' => ['sku' => 'ERG001.1']];
    $triple = json_encode(json_encode(json_encode($object)));

    expect(json_decode(ProductValuesRepairer::fix($triple), true))->toBe($object);
});

it('returns null for empty or null input', function () {
    expect(ProductValuesRepairer::fix(null))->toBeNull()
        ->and(ProductValuesRepairer::fix(''))->toBeNull();
});

it('returns null for a plain scalar string that is not a wrapped object', function () {
    expect(ProductValuesRepairer::fix(json_encode('just a string')))->toBeNull();
});
