<?php

use App\Services\CrossSellLinker;

function crossSellLinker(): CrossSellLinker
{
    return app(CrossSellLinker::class);
}

it('splits a product name into the part before and after the colour code', function (string $naam, string $base, string $code) {
    $parsed = crossSellLinker()->parseName($naam);

    expect($parsed)->not->toBeNull()
        ->and($parsed['base'])->toBe($base)
        ->and($parsed['code'])->toBe($code);
})->with([
    ['Anaheim 3243', 'anaheim', '3243'],
    ['Anaheim 3243 Rond', 'anaheim rond', '3243'],
    ['Diamante 01', 'diamante', '01'],
    ['Fading World Babylon 8545', 'fading world babylon', '8545'],
    ['Galaxy 143 Deens ovaal', 'galaxy deens ovaal', '143'],
    ['Worn Denim Light 141.132', 'worn denim light', '141.132'],
    ['Desso & Ex 3802-203', 'desso & ex', '3802-203'],
    ['Vienna BE600', 'vienna', 'BE600'],
    ['Prince 1000', 'prince', '1000'],
    // Een kleurnaam achter een liggend streepje hoort net zomin bij de basis
    // als de kleurcode zelf.
    ['Vernon 15 - Fall Grey', 'vernon', '15'],
    ['  Anaheim   3243  ', 'anaheim', '3243'],
]);

it('refuses to guess when the name carries no colour code', function (string $naam) {
    expect(crossSellLinker()->parseName($naam))->toBeNull();
})->with([
    'Nepalian',
    'Love Shaggy Beige Rond',
    'Vogue Uni Black',
    '',
    // Alleen een code, zonder naam ervoor: er blijft geen basis over.
    '3243',
]);

it('puts the same rug in two colours under one key, and other rugs under another', function () {
    $linker = crossSellLinker();

    $anaheim3243 = $linker->parseName('Anaheim 3243')['base'];
    $anaheim3434 = $linker->parseName('Anaheim 3434')['base'];
    $anaheimRond = $linker->parseName('Anaheim 3434 Rond')['base'];

    expect($anaheim3243)->toBe($anaheim3434)
        ->and($anaheimRond)->not->toBe($anaheim3243);
});
