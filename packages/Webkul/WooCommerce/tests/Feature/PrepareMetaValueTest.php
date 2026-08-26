<?php

use Webkul\WooCommerce\Helpers\Exporters\Product\Exporter;

/**
 * Build an Exporter without running its (dependency-heavy) constructor and call
 * the protected prepareMetaValue() helper.
 */
function callPrepareMetaValue(string $name, mixed $value): string
{
    $exporter = (new ReflectionClass(Exporter::class))->newInstanceWithoutConstructor();

    $method = new ReflectionMethod($exporter, 'prepareMetaValue');
    $method->setAccessible(true);

    return $method->invoke($exporter, $name, $value);
}

it('strips the editor markup from the meta description', function () {
    expect(callPrepareMetaValue('_yoast_wpseo_metadesc', '<p>Bestel je vloerkleed Diamante 01 bij Huis &amp; Wonen online.</p>'))
        ->toBe('Bestel je vloerkleed Diamante 01 bij Huis & Wonen online.');
});

it('strips the editor markup from the meta title', function () {
    expect(callPrepareMetaValue('_yoast_wpseo_title', '<strong>Vloerkleed Diamante 01</strong>'))
        ->toBe('Vloerkleed Diamante 01');
});

it('collapses the whitespace a multi-paragraph description leaves behind', function () {
    expect(callPrepareMetaValue('_yoast_wpseo_metadesc', "<p>Eerste regel.</p>\n\n<p>Tweede   regel.</p>"))
        ->toBe('Eerste regel. Tweede regel.');
});

it('leaves a value that is already plain text untouched', function () {
    expect(callPrepareMetaValue('_yoast_wpseo_title', 'Vloerkleed Diamante 01 van De Munk bij Huis & Wonen'))
        ->toBe('Vloerkleed Diamante 01 van De Munk bij Huis & Wonen');
});

it('does not touch other wrapped meta fields', function () {
    expect(callPrepareMetaValue('prijs_per_m', ' 318 '))->toBe(' 318 ');
});

it('casts a non-string value to a string', function () {
    expect(callPrepareMetaValue('maximale_lengte', 1200))->toBe('1200');
});
