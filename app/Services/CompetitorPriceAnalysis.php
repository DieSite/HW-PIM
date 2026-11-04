<?php

namespace App\Services;

use App\Models\CompetitorPrice;
use App\Models\CompetitorPriceExclusion;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Webkul\Product\Models\Product as WebkulProduct;

/**
 * Explains, per size of a rug, how our selling price relates to the
 * competition: every competitor price we know (cheapest first, our own price
 * ranked among them), which of them count, and how the price is built from
 * the advies price, the discount floor, the cheapest counting competitor and
 * a manual extra discount.
 *
 * The calculation uses the same building blocks as
 * CompetitorPricingService::recompute(), so "berekende prijs" is what the next
 * run would set.
 */
class CompetitorPriceAnalysis
{
    public const OWN_SHOP = 'Huis & Wonen';

    private const MAATWERK = ['Maatwerk', 'Rond Maatwerk'];

    public function __construct(private CompetitorPricingService $pricing) {}

    /**
     * Only the standard sizes without underlay: "Met onderkleed" prices are
     * derived from their twin and maatwerk has no competitor price.
     *
     * @param  Collection<int, WebkulProduct>  $variants
     * @return list<array{
     *     id: int, sku: string, size: string, price: ?float, advice_price: ?float,
     *     floor: ?float, max_discount: float, manual_discount: float, computed_price: ?float,
     *     cheapest: ?array{shop: string, price: float}, competitors: int, counting: int,
     *     rows: list<array{id: ?int, own: bool, shop: string, price: float, url: ?string, scraped_at: ?Carbon, stale: bool, excluded: bool, exclusion: ?array{reason: ?string, by: ?string, at: ?Carbon}, counts: bool, difference: ?float, sets_price: bool, below_advice: ?float}>
     * }>
     */
    public function forVariants(Collection $variants): array
    {
        $ids = $variants
            ->filter(fn (WebkulProduct $variant): bool => self::analysable(self::common($variant)))
            ->pluck('id')
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        /*
         * Reloaded as app models with their parent: the pricing service takes
         * App\Models\Product and reads a model-wide extra korting from the parent.
         */
        $loaded = Product::query()->with('parent')->whereIn('id', $ids)->get()->keyBy('id');
        $variants = $ids->map(fn (int $id): ?Product => $loaded->get($id))->filter()->values();

        $skus = $variants->pluck('sku')->all();

        $prices = CompetitorPrice::query()
            ->whereIn('sku', $skus)
            ->where('price', '>', 0)
            ->orderBy('price')
            ->get(['id', 'sku', 'shop', 'price', 'url', 'scraped_at'])
            ->groupBy('sku');

        $exclusions = CompetitorPriceExclusion::query()
            ->with('admin:id,name')
            ->whereIn('sku', $skus)
            ->get()
            ->keyBy(fn (CompetitorPriceExclusion $exclusion): string => self::exclusionKey($exclusion->sku, $exclusion->shop, (float) $exclusion->price));

        $maxDiscount = $this->pricing->maxDiscountPercentage();

        return $variants
            ->map(fn (Product $variant): array => $this->analyse($variant, $prices->get($variant->sku, collect()), $exclusions, $maxDiscount))
            ->all();
    }

    /**
     * Per SKU: how many competitors we know and the cheapest one that counts.
     * For the compact variants overview on the edit screen.
     *
     * @param  Collection<int, WebkulProduct>  $variants
     * @return array<string, array{competitors: int, cheapest: ?float, shop: ?string}>
     */
    public function summaries(Collection $variants): array
    {
        $summaries = [];

        foreach ($this->forVariants($variants) as $size) {
            $summaries[$size['sku']] = [
                'competitors' => $size['competitors'],
                'cheapest'    => $size['cheapest']['price'] ?? null,
                'shop'        => $size['cheapest']['shop'] ?? null,
            ];
        }

        return $summaries;
    }

    /**
     * @param  Collection<int, CompetitorPrice>  $prices
     * @param  Collection<string, CompetitorPriceExclusion>  $exclusions
     * @return array<string, mixed>
     */
    private function analyse(Product $variant, Collection $prices, Collection $exclusions, float $maxDiscount): array
    {
        $common = self::common($variant);
        $ours = self::price($common['prijs'] ?? null);
        $advies = self::price($common['adviesverkoopprijs'] ?? null);
        $manualDiscount = $this->pricing->manualDiscountPercentage($variant);

        $rows = $prices->map(function (CompetitorPrice $competitor) use ($exclusions, $ours): array {
            $exclusion = $exclusions->get(self::exclusionKey($competitor->sku, $competitor->shop, (float) $competitor->price));
            $stale = $competitor->isStale();

            return [
                'id'         => $competitor->id,
                'own'        => false,
                'shop'       => $competitor->shop,
                'price'      => (float) $competitor->price,
                'url'        => $competitor->url,
                'scraped_at' => $competitor->scraped_at,
                'stale'      => $stale,
                'excluded'   => $exclusion !== null,
                'exclusion'  => $exclusion === null ? null : [
                    'reason' => $exclusion->reason,
                    'by'     => $exclusion->admin?->name,
                    'at'     => $exclusion->created_at,
                ],
                'counts'     => ! $stale && $exclusion === null,
                'difference' => $ours !== null ? (float) $competitor->price - $ours : null,
            ];
        })->values()->all();

        $counting = array_values(array_filter($rows, fn (array $row): bool => $row['counts']));
        $cheapest = $counting[0] ?? null;

        /*
         * The cheapest counting price is the one that sets ours, and how far a
         * price sits below our advies price is what a review sorts on.
         */
        $rows = array_map(fn (array $row): array => $row + [
            'sets_price'   => $cheapest !== null && $row['id'] === $cheapest['id'],
            'below_advice' => $advies !== null && $advies > 0 ? ($advies - $row['price']) / $advies * 100 : null,
        ], $rows);

        $computed = null;
        $floor = null;

        if ($advies !== null && $advies > 0) {
            $floor = $advies * (1 - $maxDiscount / 100);
            $computed = $this->pricing->computePrice($advies, $cheapest['price'] ?? null, $maxDiscount);

            if ($manualDiscount > 0.0) {
                $computed = (float) round($computed * (1 - $manualDiscount / 100));
            }
        }

        $competitorCount = count($rows);

        if ($ours !== null) {
            $rows[] = [
                'id'           => null,
                'own'          => true,
                'shop'         => self::OWN_SHOP,
                'price'        => $ours,
                'url'          => null,
                'scraped_at'   => null,
                'stale'        => false,
                'excluded'     => false,
                'exclusion'    => null,
                'counts'       => true,
                'difference'   => 0.0,
                'sets_price'   => false,
                'below_advice' => $advies !== null && $advies > 0 ? ($advies - $ours) / $advies * 100 : null,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a['price'], ! $a['own']] <=> [$b['price'], ! $b['own']]);

        return [
            'id'              => $variant->id,
            'sku'             => $variant->sku,
            'size'            => self::text($common['maat'] ?? null) ?? $variant->sku,
            'price'           => $ours,
            'advice_price'    => $advies,
            'floor'           => $floor,
            'max_discount'    => $maxDiscount,
            'manual_discount' => $manualDiscount,
            'computed_price'  => $computed,
            'cheapest'        => $cheapest === null ? null : ['shop' => $cheapest['shop'], 'price' => $cheapest['price']],
            'competitors'     => $competitorCount,
            'counting'        => count($counting),
            'rows'            => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $common
     */
    private static function analysable(array $common): bool
    {
        return ($common['onderkleed'] ?? null) !== 'Met onderkleed'
            && ! in_array(self::text($common['maat'] ?? null), self::MAATWERK, true);
    }

    private static function exclusionKey(string $sku, string $shop, float $price): string
    {
        return $sku."\0".$shop."\0".number_format($price, 2, '.', '');
    }

    /**
     * @return array<string, mixed>
     */
    private static function common(WebkulProduct $product): array
    {
        return DeliveryTimeService::decodeValues($product->values)['common'] ?? [];
    }

    private static function price(mixed $price): ?float
    {
        $amount = is_array($price) ? ($price['EUR'] ?? null) : $price;

        return is_numeric($amount) ? (float) $amount : null;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || $value === 'null' ? null : $value;
    }
}
