<?php

use App\Services\EurogrosProductMatcher;

it('describes a rectangular product', function () {
    expect(EurogrosProductMatcher::describe('Anaheim 7626', '120 cm x 170 cm'))
        ->toBe(['anaheim', '7626', null]);
});

it('describes a round product from the maat', function () {
    expect(EurogrosProductMatcher::describe('Anaheim 4248 Rond', 'Rond 200 cm'))
        ->toBe(['anaheim', '4248', 'rond']);
});

it('describes an oval product from the productnaam suffix', function () {
    expect(EurogrosProductMatcher::describe('Twilight 2211 Ovaal', '160 cm x 230 cm'))
        ->toBe(['twilight', '2211', 'oval']);
});

it('keeps multi-word collections and sub-collection words', function () {
    expect(EurogrosProductMatcher::describe('Antiquarian Antique Heriz 8703', '240 cm x 340 cm'))
        ->toBe(['antiquarian antique heriz', '8703', null]);
});

it('normalises hyphen and leading zeros in the article number', function () {
    expect(EurogrosProductMatcher::describe('Derbe 7252-100', '140 cm x 200 cm'))
        ->toBe(['derbe 7252', '100', null])
        ->and(EurogrosProductMatcher::describe('Glow 044', '160 cm x 230 cm'))
        ->toBe(['glow', '44', null]);
});

it('matches when the PIM collection extends the CSV collection', function () {
    $csv = EurogrosProductMatcher::describe('Antiquarian 8703', '240 cm x 340 cm');
    $pim = EurogrosProductMatcher::describe('Antiquarian Antique Heriz 8703', '240 cm x 340 cm');

    expect(EurogrosProductMatcher::isMatch($csv, $pim))->toBeTrue();
});

it('does not match a different article number', function () {
    $csv = EurogrosProductMatcher::describe('Alvie 6777', '120 cm x 170 cm');
    $pim = EurogrosProductMatcher::describe('Alvie 6979', '120 cm x 170 cm');

    expect(EurogrosProductMatcher::isMatch($csv, $pim))->toBeFalse();
});

it('does not match a different collection with the same number', function () {
    $csv = EurogrosProductMatcher::describe('Malta 8727', 'Rond 240 cm');
    $pim = EurogrosProductMatcher::describe('Manchester 8727 Rond', 'Rond 240 cm');

    expect(EurogrosProductMatcher::isMatch($csv, $pim))->toBeFalse();
});

it('does not match when the shape differs (oval vs rectangle)', function () {
    $csv = EurogrosProductMatcher::describe('Twilight 2211 Ovaal', '160 cm x 230 cm');
    $pim = EurogrosProductMatcher::describe('Twilight 2211', '160 cm x 230 cm');

    expect(EurogrosProductMatcher::isMatch($csv, $pim))->toBeFalse();
});
