<?php

use App\Services\EurogrosOmschrParser;

it('resolves rectangular sizes', function () {
    expect(EurogrosOmschrParser::resolveMatch('Anaheim 7626 120x170'))
        ->toBe(['productnaam' => 'Anaheim 7626', 'maat' => '120 cm x 170 cm']);
});

it('resolves round sizes written as "<n> rond"', function () {
    expect(EurogrosOmschrParser::resolveMatch('Manchester 2959 200 rond'))
        ->toBe(['productnaam' => 'Manchester 2959', 'maat' => 'Rond 200 cm']);
});

it('treats square (vierkant) as plain dimensions', function () {
    expect(EurogrosOmschrParser::resolveMatch('Twilight 2211 200x200 vierkant'))
        ->toBe(['productnaam' => 'Twilight 2211', 'maat' => '200 cm x 200 cm']);
});

it('strips a trailing " cm" from underlay descriptions, including the double space', function () {
    expect(EurogrosOmschrParser::resolveMatch('Anti Slip Basic  240x340 cm'))
        ->toBe(['productnaam' => 'Anti Slip Basic', 'maat' => '240 cm x 340 cm']);
});

it('handles multiple trailing shape words like "cm vierkant"', function () {
    expect(EurogrosOmschrParser::resolveMatch('Aspen 7270 200x200 cm vierkant'))
        ->toBe(['productnaam' => 'Aspen 7270', 'maat' => '200 cm x 200 cm']);
});

it('suffixes the productnaam with Ovaal for oval rugs and keeps the plain maat', function () {
    expect(EurogrosOmschrParser::resolveMatch('Twilight 2211 160x230ovaal'))
        ->toBe(['productnaam' => 'Twilight 2211 Ovaal', 'maat' => '160 cm x 230 cm']);
});

it('skips organic-shape rugs (not carried)', function () {
    expect(EurogrosOmschrParser::resolveMatch('Vince 9191 160x230 organische vorm'))->toBeNull();
});

it('skips custom sizes and startersets that have no dimensions', function () {
    expect(EurogrosOmschrParser::resolveMatch('Kapiti 171 maatwerk'))->toBeNull()
        ->and(EurogrosOmschrParser::resolveMatch('James Starterset'))->toBeNull();
});

it('skips sizes that are not present in the maat map', function () {
    expect(EurogrosOmschrParser::resolveMatch('Something 9999 999x999'))->toBeNull();
});

it('resolves the round and rectangular sizes newly added to the config', function () {
    expect(EurogrosOmschrParser::resolveMatch('Twilight 2211 120 rond'))
        ->toBe(['productnaam' => 'Twilight 2211', 'maat' => 'Rond 120 cm'])
        ->and(EurogrosOmschrParser::resolveMatch('Anti Slip Basic 190x290'))
        ->toBe(['productnaam' => 'Anti Slip Basic', 'maat' => '190 cm x 290 cm']);
});
