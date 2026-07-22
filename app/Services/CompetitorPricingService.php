<?php

namespace App\Services;

use App\Models\CompetitorPrice;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use Illuminate\Support\Collection;

class CompetitorPricingService
{
    public function __construct(private ProductService $productService) {}

    /**
     * Recompute the selling price for every product matching the given SKUs and
     * sync any product whose price changed to WooCommerce + Bol.com.
     *
     * @param  array<int, string>  $skus
     * @param  array<string, array<string, array{price: float, url: ?string}>>  $previousSnapshot
     *                                                                                             Competitor prices per SKU as they were BEFORE this import, used to
     *                                                                                             explain WHY our price changed. Keyed by sku, then shop.
     */
    public function recomputeForSkus(array $skus, array $previousSnapshot = []): void
    {
        $changedVariants = [];

        Product::whereIn('sku', array_values(array_unique($skus)))
            ->whereNotNull('parent_id')
            ->chunkById(200, function (Collection $variants) use (&$changedVariants, $previousSnapshot): void {
                foreach ($variants as $variant) {
                    $history = $this->recompute($variant, $previousSnapshot[$variant->sku] ?? []);

                    if ($history !== null) {
                        $changedVariants[] = $variant;
                    }
                }
            });

        $this->dispatchSync($changedVariants);
    }

    /**
     * Recompute one variant's price. Returns the logged history row when the
     * price actually changed, or null when nothing changed / was skipped.
     *
     * @param  array<string, array{price: float, url: ?string}>  $previousForSku
     */
    public function recompute(Product $variant, array $previousForSku = []): ?ProductPriceHistory
    {
        $advies = $this->readPrice($variant, 'adviesverkoopprijs');

        if ($advies === null || $advies <= 0) {
            return null;
        }

        $pct = $this->maxDiscountPercentage();
        $floor = $advies * (1 - $pct / 100);

        $competitors = CompetitorPrice::query()
            ->where('sku', $variant->sku)
            ->where('price', '>', 0)
            ->get();

        $lowest = $competitors->sortBy('price')->first();

        $lowestPrice = $lowest?->price !== null ? (float) $lowest->price : null;
        $newPrice = $this->computePrice($advies, $lowestPrice, $pct);

        $currentPrice = $this->readPrice($variant, 'prijs');

        if ($currentPrice !== null && abs($newPrice - $currentPrice) < 0.005) {
            return null;
        }

        $values = $variant->values;
        $values['common']['prijs']['EUR'] = (string) (int) $newPrice;
        $variant->values = $values;
        $variant->saveQuietly();

        $reason = $this->buildReason(
            advies: $advies,
            floor: $floor,
            pct: $pct,
            newPrice: $newPrice,
            competitors: $competitors,
            previousForSku: $previousForSku,
            lowest: $lowest,
        );

        return ProductPriceHistory::create([
            'product_id'       => $variant->id,
            'sku'              => $variant->sku,
            'old_price'        => $currentPrice,
            'new_price'        => $newPrice,
            'reason'           => $reason,
            'competitor_shop'  => $lowest?->shop,
            'competitor_price' => $lowestPrice,
            'competitor_url'   => $lowest?->url,
            'changed_at'       => now(),
        ]);
    }

    /**
     * The dynamic selling price: the lowest of the advies price and the cheapest
     * competitor, but never more than `pct`% below the advies price. Rounded to
     * whole euros. With no competitor, it is exactly the advies price.
     */
    public function computePrice(float $advies, ?float $lowest, float $pct): float
    {
        $floor = $advies * (1 - $pct / 100);
        $target = $lowest === null ? $advies : min($advies, $lowest);

        return (float) round(max($floor, $target), 0);
    }

    /**
     * The maximum allowed discount (%) below the adviesverkoopprijs. Admin
     * editable; falls back to the config default when unset/invalid.
     */
    public function maxDiscountPercentage(): float
    {
        $configured = core()->getConfigData('general.pricing.settings.max_kortingspercentage');
        $pct = is_numeric($configured) ? (float) $configured : (float) config('competitor_pricing.default_max_discount_pct');

        if ($pct < 0 || $pct >= 100) {
            return (float) config('competitor_pricing.default_max_discount_pct');
        }

        return $pct;
    }

    /**
     * @param  Collection<int, CompetitorPrice>  $competitors
     * @param  array<string, array{price: float, url: ?string}>  $previousForSku
     */
    public function buildReason(
        float $advies,
        float $floor,
        float $pct,
        float $newPrice,
        Collection $competitors,
        array $previousForSku,
        ?CompetitorPrice $lowest,
    ): string {
        $clamped = $lowest !== null && (float) $lowest->price < $floor;

        $suffix = $clamped ? ' (begrensd op adviesprijs −'.rtrim(rtrim(number_format($pct, 2, ',', '.'), '0'), ',').'%).' : '';

        if ($lowest === null) {
            return 'Teruggezet naar adviesprijs ('.$this->euro($advies).'): geen concurrent goedkoper.'.$suffix;
        }

        $lowestShop = $lowest->shop;
        $lowestPrice = (float) $lowest->price;

        // Determine which competitor was lowest before this import.
        $prevLowestShop = null;
        $prevLowestPrice = null;
        foreach ($previousForSku as $shop => $data) {
            if ($prevLowestPrice === null || $data['price'] < $prevLowestPrice) {
                $prevLowestPrice = (float) $data['price'];
                $prevLowestShop = $shop;
            }
        }

        if ($prevLowestShop === null) {
            return 'Concurrent '.$lowestShop.' biedt '.$this->euro($lowestPrice).' — laagste concurrent.'.$suffix;
        }

        if ($prevLowestShop === $lowestShop) {
            if ($lowestPrice < $prevLowestPrice) {
                return 'Concurrent '.$lowestShop.' verlaagde naar '.$this->euro($lowestPrice).' — nieuwe laagste prijs.'.$suffix;
            }

            return 'Concurrent '.$lowestShop.' verhoogde naar '.$this->euro($lowestPrice).', maar blijft de laagste.'.$suffix;
        }

        // A different competitor is now the lowest. If the previous leader raised
        // its price, spell that out; otherwise state the new leader.
        $prevLeaderNow = $competitors->firstWhere('shop', $prevLowestShop);

        if ($prevLeaderNow !== null && (float) $prevLeaderNow->price > $prevLowestPrice) {
            return 'Concurrent '.$prevLowestShop.' verhoogde naar '.$this->euro((float) $prevLeaderNow->price)
                .'; '.$lowestShop.' is nu de laagste met '.$this->euro($lowestPrice).'.'.$suffix;
        }

        return $lowestShop.' is nu de laagste concurrent met '.$this->euro($lowestPrice).'.'.$suffix;
    }

    /**
     * Dispatch the external syncs for changed variants: WooCommerce once per
     * parent family, Bol.com per synced variant.
     *
     * @param  array<int, Product>  $changedVariants
     */
    public function dispatchSync(array $changedVariants): void
    {
        $syncedParents = [];

        foreach ($changedVariants as $variant) {
            $parent = $variant->parent;

            if ($parent !== null && ! isset($syncedParents[$parent->id])) {
                $this->productService->triggerWCSyncForParent($parent);
                $syncedParents[$parent->id] = true;
            }

            $ean = $variant->values['common']['ean'] ?? null;

            if ($variant->bol_com_sync && $ean !== null) {
                $this->productService->triggerBolSync(
                    $variant,
                    $variant->bolComCredentials->all(),
                    [],
                    true,
                );
            }
        }
    }

    private function readPrice(Product $product, string $code): ?float
    {
        $value = $product->values['common'][$code]['EUR'] ?? null;

        return $value === null || $value === '' ? null : (float) $value;
    }

    private function euro(float $value): string
    {
        return '€ '.number_format($value, 2, ',', '.');
    }
}
