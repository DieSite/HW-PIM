<?php

namespace App\Services;

use App\Models\CompetitorPrice;
use App\Models\CompetitorPriceExclusion;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Takes competitor prices out of the price analysis, or back in, and
 * recomputes the affected sizes once (CompetitorPricingService also syncs
 * them to WooCommerce and Bol). An exclusion holds for the exact amount only.
 */
class CompetitorPriceExclusionService
{
    public function __construct(private CompetitorPricingService $pricing) {}

    /**
     * @param  Collection<int, CompetitorPrice>  $prices
     * @return array{prices: int, repriced: int}
     */
    public function exclude(Collection $prices, ?string $reason, ?int $adminId): array
    {
        foreach ($prices as $price) {
            CompetitorPriceExclusion::query()->firstOrCreate(
                ['sku' => $price->sku, 'shop' => $price->shop, 'price' => $price->price],
                ['reason' => $reason, 'admin_id' => $adminId],
            );
        }

        return ['prices' => $prices->count(), 'repriced' => $this->recompute($prices)];
    }

    /**
     * @param  Collection<int, CompetitorPrice>  $prices
     * @return array{prices: int, repriced: int}
     */
    public function include(Collection $prices): array
    {
        $removed = 0;

        foreach ($prices as $price) {
            $removed += CompetitorPriceExclusion::query()
                ->where('sku', $price->sku)
                ->where('shop', $price->shop)
                ->where('price', $price->price)
                ->delete();
        }

        return ['prices' => $removed, 'repriced' => $this->recompute($prices)];
    }

    /**
     * Recompute every affected size once; returns how many sizes got a new price.
     *
     * @param  Collection<int, CompetitorPrice>  $prices
     */
    private function recompute(Collection $prices): int
    {
        $skus = $prices->pluck('sku')->unique()->values()->all();

        if ($skus === []) {
            return 0;
        }

        $before = $this->sellingPrices($skus);
        $this->pricing->recomputeForSkus($skus);
        $after = $this->sellingPrices($skus);

        return count(array_filter($after, fn (?string $price, string $sku): bool => $price !== ($before[$sku] ?? null), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * @param  list<string>  $skus
     * @return array<string, ?string>
     */
    private function sellingPrices(array $skus): array
    {
        return Product::query()
            ->whereIn('sku', $skus)
            ->get(['sku', 'values'])
            ->mapWithKeys(function (Product $product): array {
                $price = DeliveryTimeService::decodeValues($product->values)['common']['prijs'] ?? null;
                $amount = is_array($price) ? ($price['EUR'] ?? null) : $price;

                return [$product->sku => is_scalar($amount) ? (string) $amount : null];
            })
            ->all();
    }
}
