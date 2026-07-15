<?php

use Webkul\WooCommerce\Helpers\Exporters\Product\Exporter;

/**
 * Build an Exporter without running its (dependency-heavy) constructor and call
 * the protected lowestMinimalePrijs() helper with the given variants.
 *
 * @param  array<int, array<string, mixed>>  $variants
 */
function callLowestMinimalePrijs(array $variants, string $currency = 'EUR'): ?string
{
    $exporter = (new ReflectionClass(Exporter::class))->newInstanceWithoutConstructor();
    $exporter->currency = $currency;

    $method = new ReflectionMethod($exporter, 'lowestMinimalePrijs');
    $method->setAccessible(true);

    return $method->invoke($exporter, $variants);
}

it('returns the lowest minimale_prijs across the variants, formatted to two decimals', function () {
    $variants = [
        ['values' => ['common' => ['minimale_prijs' => ['EUR' => '149.9']]]],
        ['values' => ['common' => ['minimale_prijs' => ['EUR' => '89.5']]]],
        ['values' => ['common' => ['minimale_prijs' => ['EUR' => '199']]]],
    ];

    expect(callLowestMinimalePrijs($variants))->toBe('89.50');
});

it('ignores null and empty variant values when picking the lowest', function () {
    $variants = [
        ['values' => ['common' => ['minimale_prijs' => ['EUR' => null]]]],
        ['values' => ['common' => ['minimale_prijs' => ['EUR' => '']]]],
        ['values' => ['common' => ['minimale_prijs' => ['EUR' => '120']]]],
        ['values' => ['common' => []]],
    ];

    expect(callLowestMinimalePrijs($variants))->toBe('120.00');
});

it('returns null when no variant carries a minimale_prijs', function () {
    $variants = [
        ['values' => ['common' => ['minimale_prijs' => ['EUR' => null]]]],
        ['values' => ['common' => []]],
    ];

    expect(callLowestMinimalePrijs($variants))->toBeNull();
});

it('reads the value for the configured currency only', function () {
    $variants = [
        ['values' => ['common' => ['minimale_prijs' => ['USD' => '10', 'EUR' => '75']]]],
    ];

    expect(callLowestMinimalePrijs($variants))->toBe('75.00');
});

it('returns null for an empty variant set (e.g. a simple product)', function () {
    expect(callLowestMinimalePrijs([]))->toBeNull();
});
