<?php

use App\Models\CompetitorPrice;
use App\Services\CompetitorPricingService;
use Illuminate\Support\Collection;

function pricingService(): CompetitorPricingService
{
    return app(CompetitorPricingService::class);
}

/**
 * @param  array<string, float>  $shops  shop => price
 */
function competitorCollection(array $shops): Collection
{
    return collect($shops)->map(fn (float $price, string $shop) => new CompetitorPrice([
        'shop'  => $shop,
        'price' => $price,
        'url'   => 'https://'.$shop.'/product',
    ]))->values();
}

it('uses the advies price when no competitor is cheaper', function () {
    expect(pricingService()->computePrice(1000, 1100, 25))->toBe(1000.0);
});

it('uses the advies price when there is no competitor at all', function () {
    expect(pricingService()->computePrice(1000, null, 25))->toBe(1000.0);
});

it('matches a competitor that undercuts within the floor', function () {
    expect(pricingService()->computePrice(1000, 850, 25))->toBe(850.0);
});

it('never drops more than the configured percentage below advies', function () {
    // Competitor at 600 would be 40% off; floor is 25% => 750.
    expect(pricingService()->computePrice(1000, 600, 25))->toBe(750.0);
});

it('rounds the computed price to whole euros', function () {
    expect(pricingService()->computePrice(999, 949.49, 25))->toBe(949.0);
});

it('honours a different floor percentage', function () {
    expect(pricingService()->computePrice(1000, 600, 50))->toBe(600.0);
    expect(pricingService()->computePrice(1000, 400, 50))->toBe(500.0);
});

it('explains a competitor lowering its price', function () {
    $reason = pricingService()->buildReason(
        advies: 1000, floor: 750, pct: 25, newPrice: 800,
        competitors: competitorCollection(['shopa.nl' => 800, 'shopb.nl' => 900]),
        previousForSku: ['shopa.nl' => ['price' => 850, 'url' => null], 'shopb.nl' => ['price' => 900, 'url' => null]],
        lowest: new CompetitorPrice(['shop' => 'shopa.nl', 'price' => 800]),
    );

    expect($reason)->toContain('shopa.nl')->toContain('verlaagde')->toContain('nieuwe laagste');
});

it('explains the lowest competitor raising but staying lowest', function () {
    $reason = pricingService()->buildReason(
        advies: 1000, floor: 750, pct: 25, newPrice: 880,
        competitors: competitorCollection(['shopa.nl' => 880, 'shopb.nl' => 950]),
        previousForSku: ['shopa.nl' => ['price' => 820, 'url' => null], 'shopb.nl' => ['price' => 950, 'url' => null]],
        lowest: new CompetitorPrice(['shop' => 'shopa.nl', 'price' => 880]),
    );

    expect($reason)->toContain('shopa.nl')->toContain('verhoogde')->toContain('blijft de laagste');
});

it('explains a new competitor becoming lowest after the leader raised', function () {
    $reason = pricingService()->buildReason(
        advies: 1000, floor: 750, pct: 25, newPrice: 900,
        competitors: competitorCollection(['shopa.nl' => 950, 'shopb.nl' => 900]),
        previousForSku: ['shopa.nl' => ['price' => 820, 'url' => null], 'shopb.nl' => ['price' => 900, 'url' => null]],
        lowest: new CompetitorPrice(['shop' => 'shopb.nl', 'price' => 900]),
    );

    expect($reason)->toContain('shopa.nl')->toContain('verhoogde')
        ->toContain('shopb.nl')->toContain('nu de laagste');
});

it('explains a reset to advies when no competitor is present', function () {
    $reason = pricingService()->buildReason(
        advies: 1000, floor: 750, pct: 25, newPrice: 1000,
        competitors: competitorCollection([]),
        previousForSku: ['shopa.nl' => ['price' => 820, 'url' => null]],
        lowest: null,
    );

    expect($reason)->toContain('Teruggezet naar adviesprijs')->toContain('geen concurrent');
});

it('flags when the price is clamped at the floor', function () {
    $reason = pricingService()->buildReason(
        advies: 1000, floor: 750, pct: 25, newPrice: 750,
        competitors: competitorCollection(['shopa.nl' => 600]),
        previousForSku: [],
        lowest: new CompetitorPrice(['shop' => 'shopa.nl', 'price' => 600]),
    );

    expect($reason)->toContain('begrensd op adviesprijs −25%');
});
