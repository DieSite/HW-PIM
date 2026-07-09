<?php

use App\Services\DeMunkMatcher;

it('extracts the quality word from a De Munk quality or productnaam', function () {
    expect(DeMunkMatcher::qualityWord('Diamante 08'))->toBe('Diamante')
        ->and(DeMunkMatcher::qualityWord('DIAMANTE R'))->toBe('DIAMANTE')
        ->and(DeMunkMatcher::qualityWord('MODERN'))->toBe('MODERN')
        ->and(DeMunkMatcher::qualityWord('  Firenze 21  '))->toBe('Firenze');
});

it('extracts the design number from a productnaam', function () {
    expect(DeMunkMatcher::productnaamNumber('Diamante 08'))->toBe(8)
        ->and(DeMunkMatcher::productnaamNumber('Diamante 08 Rond'))->toBe(8)
        ->and(DeMunkMatcher::productnaamNumber('Firenze 21'))->toBe(21)
        ->and(DeMunkMatcher::productnaamNumber('Zonder nummer'))->toBeNull();
});

it('extracts the colour number from a De Munk colour code', function () {
    expect(DeMunkMatcher::colourNumber('DI-08'))->toBe(8)
        ->and(DeMunkMatcher::colourNumber('FI-21'))->toBe(21)
        ->and(DeMunkMatcher::colourNumber('CO-01'))->toBe(1)
        ->and(DeMunkMatcher::colourNumber('geen'))->toBe(0);
});

it('maps a De Munk quality to a shape, leaving ambiguous suffixes unmatched', function () {
    expect(DeMunkMatcher::vormClassForQuality('DIAMANTE'))->toBe('Rechthoek')
        ->and(DeMunkMatcher::vormClassForQuality('DIAMANTE R'))->toBe('Rond')
        ->and(DeMunkMatcher::vormClassForQuality('NAPOLI SPE'))->toBeNull()
        ->and(DeMunkMatcher::vormClassForQuality('TAFRAOUT I'))->toBeNull();
});

it('produces the same key for a PIM product and its matching De Munk article', function () {
    // PIM side: product "Diamante 08", collectie Modern, vorm Rechthoek.
    $productKey = DeMunkMatcher::productKey(
        'Modern',
        DeMunkMatcher::qualityWord('Diamante 08'),
        DeMunkMatcher::productnaamNumber('Diamante 08'),
        'Rechthoek',
    );

    // De Munk side: MODERN / DIAMANTE / DI-08.
    $articleKey = DeMunkMatcher::productKey(
        'MODERN',
        DeMunkMatcher::qualityWord('DIAMANTE'),
        DeMunkMatcher::colourNumber('DI-08'),
        DeMunkMatcher::vormClassForQuality('DIAMANTE'),
    );

    expect($productKey)->toBe($articleKey)
        ->and($productKey)->toBe('MODERN|DIAMANTE|8|Rechthoek');
});

it('does not collide different colours or shapes', function () {
    $di08 = DeMunkMatcher::productKey('MODERN', 'DIAMANTE', 8, 'Rechthoek');

    expect($di08)
        ->not->toBe(DeMunkMatcher::productKey('MODERN', 'DIAMANTE', 3, 'Rechthoek'))
        ->not->toBe(DeMunkMatcher::productKey('MODERN', 'DIAMANTE', 8, 'Rond'))
        ->not->toBe(DeMunkMatcher::productKey('MODERN', 'FIRENZE', 8, 'Rechthoek'));
});
