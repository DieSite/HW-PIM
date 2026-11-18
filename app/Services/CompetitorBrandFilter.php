<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Brands we no longer sell (config `competitor_pricing.excluded_brands`) stay
 * in the PIM but take no part in the competitor analysis: they are not
 * scraped, their competitor prices are removed, and their selling price is
 * left exactly as it is.
 *
 * Leaving the price alone matters. Without the guard in the pricing service,
 * removing their competitor prices would make the next recompute put every
 * one of them back on the adviesverkoopprijs and sync that to the shop.
 *
 * The brand is the `merk` of the parent (the variant's own as a fallback) and
 * is matched as a whole value, case-insensitive, so excluding "Karpi" never
 * catches the combined "Mart Visser|Karpi".
 */
class CompetitorBrandFilter
{
    public function isExcluded(Product $variant): bool
    {
        $excluded = $this->excludedBrands();

        if ($excluded === []) {
            return false;
        }

        $brand = $this->brandOf($variant);

        return $brand !== null && in_array(mb_strtolower($brand), $excluded, true);
    }

    /**
     * The subset of these SKUs that belongs to an excluded brand, as a lookup.
     *
     * @param  array<int, string>  $skus
     * @return array<string, true>
     */
    public function excludedSkus(array $skus): array
    {
        if ($this->excludedBrands() === [] || $skus === []) {
            return [];
        }

        $excluded = [];

        foreach (array_chunk(array_values(array_unique($skus)), 500) as $chunk) {
            Product::query()
                ->whereIn('sku', $chunk)
                ->with('parent:id,values')
                ->select(['id', 'sku', 'parent_id', 'values'])
                ->get()
                ->each(function (Product $product) use (&$excluded): void {
                    if ($this->isExcluded($product)) {
                        $excluded[$product->sku] = true;
                    }
                });
        }

        return $excluded;
    }

    /**
     * Constrain a query to rows whose SKU does not belong to an excluded brand.
     *
     * The SQL twin of isExcluded(), for the report queries that read the JSON
     * column directly instead of hydrating tens of thousands of products: the
     * parent's `merk`, the variant's own as a fallback, matched as a whole
     * value, case-insensitive. A SKU without a product row is kept.
     *
     * @template TQuery of EloquentBuilder|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function withoutExcludedSkus(EloquentBuilder|QueryBuilder $query, string $skuColumn): EloquentBuilder|QueryBuilder
    {
        $excluded = $this->excludedBrands();

        if ($excluded === []) {
            return $query;
        }

        return $query->whereNotExists(function (QueryBuilder $sub) use ($excluded, $skuColumn): void {
            $sub->selectRaw('1')
                ->from('products as brand_variant')
                ->leftJoin('products as brand_parent', 'brand_parent.id', '=', 'brand_variant.parent_id')
                ->whereColumn('brand_variant.sku', $skuColumn)
                ->whereIn(DB::raw(
                    "LOWER(TRIM(COALESCE(NULLIF(brand_parent.`values`->>'$.common.merk', 'null'), NULLIF(brand_variant.`values`->>'$.common.merk', 'null'))))"
                ), $excluded);
        });
    }

    /**
     * @return array<int, string>
     */
    public function excludedBrands(): array
    {
        return Collection::make(config('competitor_pricing.excluded_brands', []))
            ->filter(fn (mixed $brand): bool => is_string($brand) && trim($brand) !== '')
            ->map(fn (string $brand): string => mb_strtolower(trim($brand)))
            ->values()
            ->all();
    }

    private function brandOf(Product $variant): ?string
    {
        $brand = ($variant->parent !== null ? $this->common($variant->parent)['merk'] ?? null : null)
            ?? $this->common($variant)['merk'] ?? null;

        return is_string($brand) && trim($brand) !== '' ? trim($brand) : null;
    }

    /**
     * The `common` scope of a product's values, tolerating the double-encoded
     * `values` column some legacy rows still carry.
     *
     * @return array<string, mixed>
     */
    private function common(Product $product): array
    {
        $values = $product->values;

        if (is_string($values)) {
            $values = json_decode($values, true);
        }

        return is_array($values) ? ($values['common'] ?? []) : [];
    }
}
