<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Webkul\DAM\Models\Asset;

/**
 * Read-only product lookup behind the "eenvoudige modus" for showroom and
 * office staff: find rugs by name, brand, collection, colour, SKU or EAN, see
 * the stock of every size at a glance, and open one rug for all its details.
 *
 * Only the "Zonder onderkleed" variants are listed as sizes: their "Met
 * onderkleed" twins carry the same stock (ProductService::copyStockValuesOnderkleed)
 * and only add a price, which is shown next to the size instead.
 */
class StockLookupService
{
    public const MAX_RUGS = 40;

    public const FILTER_ALL = 'alles';

    public const FILTER_IN_STOCK = 'op-voorraad';

    public const FILTER_SHOWROOM = 'showroom';

    public const FILTERS = [self::FILTER_ALL, self::FILTER_IN_STOCK, self::FILTER_SHOWROOM];

    /**
     * Product properties shown on the detail page, in this order, with the
     * label staff know them by.
     */
    public const SPECIFICATIONS = [
        'kwaliteit'           => 'Kwaliteit',
        'materiaal'           => 'Materiaal',
        'loopvlak'            => 'Loopvlak',
        'poolhoogte'          => 'Poolhoogte',
        'productie_techniek'  => 'Productietechniek',
        'gewicht'             => 'Gewicht',
        'kleuren'             => 'Kleur',
        'patroon'             => 'Patroon',
        'vorm'                => 'Vorm',
        'randafwerking'       => 'Randafwerking',
        'afwerking'           => 'Afwerking',
        'gebruik'             => 'Gebruik',
        'productieland'       => 'Productieland',
        'garantie'            => 'Garantie',
        'onderhoudsadvies'    => 'Onderhoud',
        'maximale_breedte_cm' => 'Maatwerk: max. breedte',
        'maximale_lengte_cm'  => 'Maatwerk: max. lengte',
        'maximale_diameter_cm'=> 'Maatwerk: max. diameter',
        'in_collectie'        => 'In collectie',
    ];

    private const MAATWERK = ['Maatwerk', 'Rond Maatwerk'];

    private const WITH_UNDERLAY = 'Met onderkleed';

    /**
     * Words in the search box are matched against these properties of the rug.
     */
    private const SEARCHABLE = ['productnaam', 'merk', 'collectie', 'kleuren', 'kwaliteit'];

    public function __construct(private CompetitorPriceAnalysis $analysis) {}

    /**
     * @return array{rugs: list<array<string, mixed>>, truncated: bool}
     */
    public function search(string $query, string $filter = self::FILTER_ALL): array
    {
        $terms = $this->terms($query);

        if ($terms === [] && $filter === self::FILTER_ALL) {
            return ['rugs' => [], 'truncated' => false];
        }

        $parents = $this->parentQuery($terms, $filter)
            ->orderByRaw(self::field('productnaam'))
            ->limit(self::MAX_RUGS + 1)
            ->get(['id', 'sku', 'status', 'values']);

        $truncated = $parents->count() > self::MAX_RUGS;
        $parents = $parents->take(self::MAX_RUGS);

        $variants = $this->activeVariants($parents->pluck('id')->all());
        $thumbnails = $this->firstImages($parents);

        $rugs = [];

        foreach ($parents as $parent) {
            $sizes = $this->sizes($variants->get($parent->id, collect()), $filter);

            if ($filter !== self::FILTER_ALL && $sizes === []) {
                continue;
            }

            $rugs[] = $this->summary($parent, $thumbnails[$parent->id] ?? null) + ['sizes' => $sizes];
        }

        return ['rugs' => $rugs, 'truncated' => $truncated];
    }

    /**
     * Everything staff may want to know about one rug.
     *
     * @return array<string, mixed>
     */
    public function detail(Product $rug): array
    {
        $common = self::common($rug);
        $variants = $this->activeVariants([$rug->id])->get($rug->id, collect());
        $firstVariant = $variants->map(fn (Product $variant): array => self::common($variant))->first() ?? [];

        $specifications = [];

        foreach (self::SPECIFICATIONS as $code => $label) {
            $value = self::list($common[$code] ?? null) ?? self::list($firstVariant[$code] ?? null);

            if ($value !== null) {
                $specifications[$label] = $value;
            }
        }

        $pricePerM2 = self::price($common['prijs_per_m2'] ?? null);

        if ($pricePerM2 !== null) {
            $specifications['Maatwerk: prijs per m²'] = '€ '.number_format($pricePerM2, 2, ',', '.');
        }

        return $this->summary($rug, null) + [
            'images'         => $this->images(self::assetIds($common['afbeelding'] ?? null)),
            'specifications' => $specifications,
            'description'    => self::paragraphs($common['beschrijving_l'] ?? null),
            'short'          => self::paragraphs($common['beschrijving_k'] ?? null),
            'sizes'          => $sizes = $this->sizes($variants, self::FILTER_ALL),
            'price_analysis' => $this->priceAnalysis($variants, $sizes),
            'related'        => $this->related($rug, $common),
        ];
    }

    /**
     * @return array{id: int, sku: string, name: string, brand: ?string, collection: ?string, colour: ?string, active: bool, thumbnail: ?string}
     */
    private function summary(Product $rug, ?string $thumbnail): array
    {
        $common = self::common($rug);

        return [
            'id'         => $rug->id,
            'sku'        => $rug->sku,
            'name'       => self::text($common['productnaam'] ?? null) ?? $rug->sku,
            'brand'      => self::text($common['merk'] ?? null),
            'collection' => self::text($common['collectie'] ?? null),
            'colour'     => self::list($common['kleuren'] ?? null),
            'active'     => (bool) $rug->status,
            'thumbnail'  => $thumbnail,
        ];
    }

    /**
     * The other colours of the rug: its cross-sells, which the shop shows as
     * "andere kleuren".
     *
     * @param  array<string, mixed>  $common
     * @return list<array{id: int, sku: string, name: string, brand: ?string, collection: ?string, colour: ?string, active: bool, thumbnail: ?string}>
     */
    private function related(Product $rug, array $common): array
    {
        $values = DeliveryTimeService::decodeValues($rug->values);
        $skus = array_values(array_diff(array_filter((array) ($values['associations']['cross_sells'] ?? []), 'is_string'), [$rug->sku]));

        if ($skus === []) {
            return [];
        }

        $others = Product::query()
            ->whereNull('parent_id')
            ->whereIn('sku', $skus)
            ->where('status', 1)
            ->get(['id', 'sku', 'status', 'values'])
            ->sortBy(fn (Product $other): int => (int) array_search($other->sku, $skus, true));

        $thumbnails = $this->firstImages($others);

        return $others->map(fn (Product $other): array => $this->summary($other, $thumbnails[$other->id] ?? null))->values()->all();
    }

    /**
     * Rugs (parents and stand-alone products) whose searchable properties or
     * SKU contain every search word, or that have a variant whose SKU or EAN
     * matches the whole query.
     *
     * @param  list<string>  $terms
     */
    private function parentQuery(array $terms, string $filter): Builder
    {
        $fields = array_map(fn (string $attribute): string => self::field($attribute), self::SEARCHABLE);
        $haystack = 'LOWER(CONCAT_WS(\' \', '.implode(', ', [...$fields, '`sku`']).'))';

        $builder = Product::query()->whereNull('parent_id');

        if ($terms !== []) {
            $whole = implode(' ', $terms);

            $builder->where(function (Builder $query) use ($terms, $haystack, $whole): void {
                $query->where(function (Builder $query) use ($terms, $haystack): void {
                    foreach ($terms as $term) {
                        $query->whereRaw("{$haystack} LIKE ?", ['%'.self::escapeLike($term).'%']);
                    }
                })->orWhereIn('id', Product::query()
                    ->select('parent_id')
                    ->whereNotNull('parent_id')
                    ->where(function (Builder $query) use ($whole): void {
                        $query->whereRaw('LOWER(`sku`) LIKE ?', [self::escapeLike($whole).'%'])
                            ->orWhereRaw(self::field('ean').' = ?', [$whole]);
                    }));
            });
        }

        if ($filter !== self::FILTER_ALL) {
            $builder->whereExists(function ($query) use ($filter): void {
                $query->selectRaw('1')
                    ->from('products as variant')
                    ->whereColumn('variant.parent_id', 'products.id')
                    ->where('variant.status', 1)
                    ->whereRaw($this->stockCondition($filter, 'variant'));
            });
        }

        return $builder;
    }

    private function stockCondition(string $filter, string $table): string
    {
        $showroom = self::stockField(DeliveryTimeService::SHOWROOM_STOCK, $table);

        if ($filter === self::FILTER_SHOWROOM) {
            return "{$showroom} > 0";
        }

        $supplier = implode(' + ', array_map(
            fn (string $field): string => self::stockField($field, $table),
            DeliveryTimeService::SUPPLIER_STOCK,
        ));

        return "({$showroom} + {$supplier}) > 0";
    }

    /**
     * The competitor analysis per standard size, in the order of the size table.
     *
     * @param  Collection<int, Product>  $variants
     * @param  list<array<string, mixed>>  $sizes
     * @return list<array<string, mixed>>
     */
    private function priceAnalysis(Collection $variants, array $sizes): array
    {
        $order = array_flip(array_column($sizes, 'sku'));
        $analysis = $this->analysis->forVariants($variants);

        usort($analysis, fn (array $a, array $b): int => ($order[$a['sku']] ?? PHP_INT_MAX) <=> ($order[$b['sku']] ?? PHP_INT_MAX));

        return $analysis;
    }

    /**
     * @param  list<int>  $parentIds
     * @return Collection<int, Collection<int, Product>>
     */
    private function activeVariants(array $parentIds): Collection
    {
        return Product::query()
            ->whereIn('parent_id', $parentIds)
            ->where('status', 1)
            ->get(['id', 'sku', 'parent_id', 'values'])
            ->groupBy('parent_id');
    }

    /**
     * @param  Collection<int, Product>  $variants
     * @return list<array{id: int, sku: string, ean: ?string, size: string, custom: bool, showroom: int, supplier: int, sale: int, price: ?float, advice_price: ?float, underlay_price: ?float, delivery_time: ?string}>
     */
    private function sizes(Collection $variants, string $filter): array
    {
        $underlayPrices = [];

        foreach ($variants as $variant) {
            $common = self::common($variant);

            if (($common['onderkleed'] ?? null) === self::WITH_UNDERLAY) {
                $underlayPrices[self::text($common['maat'] ?? null) ?? ''] = self::price($common['prijs'] ?? null);
            }
        }

        $sizes = [];

        foreach ($variants as $variant) {
            $common = self::common($variant);

            if (($common['onderkleed'] ?? null) === self::WITH_UNDERLAY) {
                continue;
            }

            $size = self::text($common['maat'] ?? null) ?? $variant->sku;
            $custom = in_array($size, self::MAATWERK, true);
            $price = self::price($common['prijs'] ?? null);
            $advicePrice = self::price($common['adviesverkoopprijs'] ?? null);

            $row = [
                'id'             => $variant->id,
                'sku'            => $variant->sku,
                'ean'            => self::text($common['ean'] ?? null),
                'size'           => $size,
                'custom'         => $custom,
                'showroom'       => $custom ? 0 : self::stock($common, [DeliveryTimeService::SHOWROOM_STOCK]),
                'supplier'       => $custom ? 0 : self::stock($common, DeliveryTimeService::SUPPLIER_STOCK),
                'sale'           => $custom ? 0 : self::stock($common, ['uitverkoop_15_korting']),
                'price'          => $price,
                'advice_price'   => $advicePrice !== null && $price !== null && $advicePrice > $price ? $advicePrice : null,
                'underlay_price' => $underlayPrices[$size] ?? null,
                'delivery_time'  => self::text($common[DeliveryTimeService::ATTRIBUTE] ?? null),
                'sort'           => (int) ($common['sorteer_volgorde'] ?? 0),
            ];

            if ($filter === self::FILTER_SHOWROOM && $row['showroom'] === 0) {
                continue;
            }

            if ($filter === self::FILTER_IN_STOCK && $row['showroom'] + $row['supplier'] === 0) {
                continue;
            }

            $sizes[] = $row;
        }

        usort($sizes, fn (array $a, array $b): int => [$a['custom'], $a['sort'], $a['size']] <=> [$b['custom'], $b['sort'], $b['size']]);

        return array_map(function (array $row): array {
            unset($row['sort']);

            return $row;
        }, $sizes);
    }

    /**
     * Thumbnail URL of the first `afbeelding` asset of every rug.
     *
     * @param  Collection<int, Product>  $rugs
     * @return array<int, string>
     */
    private function firstImages(Collection $rugs): array
    {
        $firstAssetIds = $rugs->mapWithKeys(function (Product $rug): array {
            $first = self::assetIds(self::common($rug)['afbeelding'] ?? null)[0] ?? null;

            return $first !== null ? [$rug->id => $first] : [];
        });

        $paths = Asset::query()->whereIn('id', $firstAssetIds->values()->unique())->pluck('path', 'id');

        return $firstAssetIds
            ->filter(fn (int $assetId): bool => isset($paths[$assetId]))
            ->map(fn (int $assetId): string => route('admin.dam.file.thumbnail', ['path' => $paths[$assetId]]))
            ->all();
    }

    /**
     * @param  list<int>  $assetIds
     * @return list<array{thumbnail: string, large: string}>
     */
    private function images(array $assetIds): array
    {
        $paths = Asset::query()->whereIn('id', $assetIds)->pluck('path', 'id');

        return collect($assetIds)
            ->filter(fn (int $id): bool => isset($paths[$id]))
            ->map(fn (int $id): array => [
                'thumbnail' => route('admin.dam.file.thumbnail', ['path' => $paths[$id]]),
                'large'     => route('admin.dam.file.preview', ['path' => $paths[$id], 'size' => 1600]),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private static function assetIds(mixed $images): array
    {
        $ids = is_array($images) ? $images : explode(',', (string) $images);

        return array_values(array_filter(array_map(fn (mixed $id): int => (int) trim((string) $id), $ids), fn (int $id): bool => $id > 0));
    }

    /**
     * Rich-text attribute as plain paragraphs, so the page never renders
     * stored HTML.
     *
     * @return list<string>
     */
    private static function paragraphs(mixed $html): array
    {
        if (! is_string($html) || trim($html) === '') {
            return [];
        }

        $text = preg_replace(['#<br\s*/?>#i', '#</(p|div|li|h\d)>#i'], "\n", $html);
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return array_values(array_filter(array_map('trim', preg_split('/\n+/', $text) ?: []), fn (string $line): bool => $line !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    private static function common(Product $product): array
    {
        return DeliveryTimeService::decodeValues($product->values)['common'] ?? [];
    }

    /**
     * @return list<string>
     */
    private function terms(string $query): array
    {
        return array_values(array_filter(
            preg_split('/\s+/', mb_strtolower(trim($query))) ?: [],
            fn (string $term): bool => $term !== '',
        ));
    }

    private static function field(string $attribute): string
    {
        return "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`values`, '$.common.{$attribute}')), '')";
    }

    private static function stockField(string $attribute, string $table): string
    {
        return "CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`{$table}`.`values`, '$.common.{$attribute}')), 'null'), '0') AS SIGNED)";
    }

    /**
     * @param  array<string, mixed>  $common
     * @param  list<string>  $fields
     */
    private static function stock(array $common, array $fields): int
    {
        return array_sum(array_map(fn (string $field): int => max(0, (int) ($common[$field] ?? 0)), $fields));
    }

    private static function price(mixed $price): ?float
    {
        $amount = is_array($price) ? ($price['EUR'] ?? null) : $price;

        return is_numeric($amount) ? (float) $amount : null;
    }

    /**
     * Multi-value text attributes are stored pipe-separated ("Beige|Ecru").
     */
    private static function list(mixed $value): ?string
    {
        $value = self::text($value);

        return $value === null ? null : implode(', ', array_filter(array_map('trim', explode('|', $value))));
    }

    private static function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || $value === 'null' ? null : $value;
    }

    private static function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
