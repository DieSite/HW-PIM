<?php

namespace App\Services;

use App\Models\CompetitorPrice;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The rugs whose selling price sits at least a given percentage below the
 * adviesverkoopprijs, per size, with every competitor price behind it. Feeds
 * Tools → Concurrentieprijzen, where suspicious price points can be excluded.
 *
 * Only the sizes that set their own price count: standard sizes without
 * underlay ("Met onderkleed" and maatwerk follow from them).
 */
class CompetitorDiscountOverview
{
    public const DEFAULT_MIN_DISCOUNT = 20.0;

    public function __construct(private CompetitorPriceAnalysis $analysis) {}

    /**
     * In "excluded only" mode the discount threshold does not apply: once a
     * price is excluded our price usually rises, often out of the threshold,
     * and the exclusion must stay findable to undo it.
     *
     * @param  array{min_discount: float, excluded_only?: bool, brand?: ?string, search?: ?string, shop?: ?string}  $filters
     * @return array{rugs: LengthAwarePaginator, sizes: int, excluded: int}
     */
    public function rugs(array $filters, int $perPage): array
    {
        $minDiscount = $filters['min_discount'];
        $excludedOnly = (bool) ($filters['excluded_only'] ?? false);

        $page = $this->discountedSizes($filters)
            ->select('products.parent_id')
            ->selectRaw('MAX(COALESCE('.self::discount().', -1)) as max_discount')
            ->groupBy('products.parent_id')
            ->orderByDesc('max_discount')
            ->orderBy('products.parent_id')
            ->paginate($perPage)
            ->withQueryString();

        $sizes = $this->discountedSizes($filters)->count();

        $parentIds = collect($page->items())->pluck('parent_id')->all();

        $parents = Product::query()->whereIn('id', $parentIds)->get(['id', 'sku', 'status', 'values'])->keyBy('id');

        $variants = $this->discountedSizes($filters)
            ->whereIn('products.parent_id', $parentIds)
            ->get(['products.id', 'products.sku', 'products.parent_id', 'products.values'])
            ->groupBy('parent_id');

        $rugs = collect($page->items())->map(function ($row) use ($parents, $variants, $minDiscount, $excludedOnly): ?array {
            $parent = $parents->get($row->parent_id);

            if ($parent === null) {
                return null;
            }

            $common = DeliveryTimeService::decodeValues($parent->values)['common'] ?? [];

            /*
             * Only the sizes that meet the threshold (or, in excluded-only
             * mode, that have an excluded price), each with every competitor
             * price behind it.
             */
            $sizes = collect($this->analysis->forVariants(VariantOrder::sort($variants->get($parent->id, collect()))))
                ->map(fn (array $size): array => $size + ['discount' => self::discountOf($size)])
                ->filter(fn (array $size): bool => $excludedOnly
                    ? collect($size['rows'])->contains('excluded', true)
                    : $size['discount'] !== null && $size['discount'] >= $minDiscount - 0.0001)
                ->values()
                ->all();

            return [
                'id'           => $parent->id,
                'sku'          => $parent->sku,
                'name'         => self::text($common['productnaam'] ?? null) ?? $parent->sku,
                'brand'        => self::text($common['merk'] ?? null),
                'max_discount' => max(0.0, (float) $row->max_discount * 100),
                'sizes'        => $sizes,
            ];
        })->filter()->values();

        $page->setCollection($rugs);

        /* All excluded prices within brand/name/shop, whatever the discount: for the link to that view. */
        $excluded = CompetitorPrice::query()
            ->whereIn('sku', $this->discountedSizes(['excluded_only' => true] + $filters)->select('products.sku'))
            ->excluded()
            ->count();

        return ['rugs' => $page, 'sizes' => $sizes, 'excluded' => $excluded];
    }

    /**
     * @return list<string>
     */
    public function brands(): array
    {
        return Product::query()
            ->whereNull('parent_id')
            ->selectRaw(self::field('merk', 'products').' as brand')
            ->distinct()
            ->orderBy('brand')
            ->pluck('brand')
            ->filter(fn (?string $brand): bool => $brand !== null && $brand !== '' && $brand !== 'null')
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function shops(): array
    {
        return CompetitorPrice::query()->distinct()->orderBy('shop')->pluck('shop')->all();
    }

    /**
     * Sizes that set their own price and sit at least min_discount% below
     * advies, or in excluded-only mode: that have an excluded competitor price.
     *
     * @param  array{min_discount: float, excluded_only?: bool, brand?: ?string, search?: ?string, shop?: ?string}  $filters
     */
    private function discountedSizes(array $filters): Builder
    {
        $query = Product::query()
            ->whereNotNull('products.parent_id')
            ->where('products.status', 1)
            ->whereRaw(self::field('onderkleed', 'products')." <> 'Met onderkleed'")
            ->whereRaw(self::field('maat', 'products')." NOT IN ('Maatwerk', 'Rond Maatwerk')");

        if ($filters['excluded_only'] ?? false) {
            $query->whereIn('products.sku', CompetitorPrice::query()->excluded()->select('competitor_prices.sku'));
        } else {
            $query->whereRaw(self::amount('adviesverkoopprijs').' > 0')
                ->whereRaw(self::amount('prijs').' > 0')
                ->whereRaw(self::discount().' >= ?', [$filters['min_discount'] / 100 - 0.000001]);
        }

        $brand = $filters['brand'] ?? null;

        if ($brand !== null && $brand !== '') {
            $query->whereExists(fn ($parents) => $parents->selectRaw('1')
                ->from('products as parent')
                ->whereColumn('parent.id', 'products.parent_id')
                ->whereRaw(self::field('merk', 'parent').' = ?', [$brand]));
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.addcslashes(mb_strtolower($search), '\\%_').'%';

            $query->where(fn (Builder $query) => $query
                ->whereRaw('LOWER(products.sku) LIKE ?', [$like])
                ->orWhereExists(fn ($parents) => $parents->selectRaw('1')
                    ->from('products as parent')
                    ->whereColumn('parent.id', 'products.parent_id')
                    ->whereRaw('LOWER('.self::field('productnaam', 'parent').') LIKE ?', [$like])));
        }

        $shop = $filters['shop'] ?? null;

        if ($shop !== null && $shop !== '') {
            $query->whereExists(fn ($prices) => $prices->selectRaw('1')
                ->from('competitor_prices')
                ->whereColumn('competitor_prices.sku', 'products.sku')
                ->where('competitor_prices.shop', $shop));
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $size
     */
    private static function discountOf(array $size): ?float
    {
        if ($size['advice_price'] === null || $size['advice_price'] <= 0 || $size['price'] === null) {
            return null;
        }

        return ($size['advice_price'] - $size['price']) / $size['advice_price'] * 100;
    }

    private static function discount(): string
    {
        return '(('.self::amount('adviesverkoopprijs').' - '.self::amount('prijs').') / '.self::amount('adviesverkoopprijs').')';
    }

    private static function amount(string $attribute): string
    {
        return "CAST(NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(products.`values`, '$.common.{$attribute}.EUR')), 'null'), '') AS DECIMAL(10,2))";
    }

    private static function field(string $attribute, string $table): string
    {
        return "COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$table}.`values`, '$.common.{$attribute}')), '')";
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
