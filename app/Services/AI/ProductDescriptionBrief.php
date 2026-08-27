<?php

namespace App\Services\AI;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Assembles everything the model is allowed to know about one rug.
 *
 * Always built from the configurable parent: variants carry only sku, maat,
 * prices, stock and lead times — every descriptive attribute on them is null,
 * and the shop reads the description from the parent too.
 *
 * The size and lead-time block is computed from the variants rather than copied
 * from a text field, because that is what the "korte beschrijving" is made of
 * and an invented size is the one error that is directly harmful.
 *
 * @phpstan-type Brief array<string, mixed>
 */
class ProductDescriptionBrief
{
    /**
     * Attributes describing what the rug is made of and how.
     */
    private const CONSTRUCTION = [
        'materiaal', 'loopvlak', 'kwaliteit', 'productie_techniek',
        'productieland', 'poolhoogte', 'gewicht', 'randafwerking',
    ];

    /**
     * Attributes about using and caring for it.
     */
    private const CARE = ['gebruik', 'onderhoudsadvies', 'garantie'];

    /**
     * Attributes stored as a pipe-separated list.
     */
    private const MULTI_VALUE = ['kleuren', 'materiaal', 'loopvlak', 'merk'];

    /**
     * Sizes that mean "made to measure" rather than a stock size.
     */
    private const MADE_TO_MEASURE = ['Maatwerk', 'Rond Maatwerk', 'Vierkant Maatwerk'];

    /**
     * @param  array<string, mixed>  $overrides  Unsaved form values, which win over what is stored.
     * @return Brief
     */
    public function build(Product $product, array $overrides = []): array
    {
        $common = $this->common($product, $overrides);
        $variants = $this->variants($product);

        return [
            'identiteit' => array_filter([
                'sku'         => (string) $product->sku,
                'productnaam' => $this->text($common, 'productnaam'),
                'merk'        => $this->list($common, 'merk'),
                'collectie'   => $this->text($common, 'collectie'),
            ]),
            'uiterlijk' => array_filter([
                'kleuren' => $this->list($common, 'kleuren'),
                'patroon' => $this->text($common, 'patroon'),
                'vorm'    => $this->text($common, 'vorm'),
            ]),
            'constructie'       => $this->group($common, self::CONSTRUCTION),
            'gebruik_onderhoud' => $this->group($common, self::CARE),
            'maten_en_levering' => $this->sizes($common, $variants),
            'context'           => array_filter([
                'categorieen'           => $this->categories($product),
                'aantal_kleurvarianten' => $this->colourwayCount($product),
            ]),
        ];
    }

    /**
     * Build a brief straight from a create-form payload, for products that do
     * not exist yet. Only the common values are available then — no variants,
     * so the size block stays empty and the model is told not to mention sizes.
     *
     * @param  array<string, mixed>  $values
     * @return Brief
     */
    public function buildFromValues(array $values): array
    {
        $common = $this->normalise($values);

        return [
            'identiteit' => array_filter([
                'sku'         => (string) ($values['sku'] ?? ''),
                'productnaam' => $this->text($common, 'productnaam'),
                'merk'        => $this->list($common, 'merk'),
                'collectie'   => $this->text($common, 'collectie'),
            ]),
            'uiterlijk' => array_filter([
                'kleuren' => $this->list($common, 'kleuren'),
                'patroon' => $this->text($common, 'patroon'),
                'vorm'    => $this->text($common, 'vorm'),
            ]),
            'constructie'       => $this->group($common, self::CONSTRUCTION),
            'gebruik_onderhoud' => $this->group($common, self::CARE),
            'maten_en_levering' => $this->maxDimensions($common),
            'context'           => [],
        ];
    }

    /**
     * The parent's `values.common`, cleaned: pipe lists stay intact here, but
     * the literal string "null" and blank values are dropped. Those are real —
     * "null" appears 835 times in loopvlak alone.
     *
     * Overrides carry what the editor has typed but not yet saved. They win,
     * because otherwise changing the colour and pressing the button would hand
     * the model the old colour. On a freshly created product the stored values
     * are near-empty and the form is all there is.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function common(Product $product, array $overrides = []): array
    {
        $values = $product->values;

        if (is_string($values)) {
            $values = json_decode($values, true);
        }

        $stored = is_array($values) ? ($values['common'] ?? []) : [];

        return $this->normalise([...$stored, ...$this->normalise($overrides)]);
    }

    /**
     * Every size string the texts are allowed to mention.
     *
     * @param  array<string, mixed>  $overrides
     * @return list<string>
     */
    public function allowedSizes(Product $product, array $overrides = []): array
    {
        $sizes = $this->variants($product)
            ->map(fn (Product $variant) => is_array($variant->values)
                ? ($variant->values['common']['maat'] ?? null)
                : null)
            ->filter(fn ($maat): bool => is_string($maat) && $maat !== '')
            ->unique()
            ->values()
            ->all();

        $common = $this->common($product, $overrides);

        foreach (['maximale_breedte_cm', 'maximale_lengte_cm'] as $code) {
            if ($value = $this->text($common, $code)) {
                $sizes[] = $value;
            }
        }

        return array_values(array_unique($sizes));
    }

    /**
     * Sizes, made-to-measure options, maximum dimensions, lead times and the
     * price range — all derived from the active variants.
     *
     * Prices come from the "Zonder onderkleed" variants only: virtually every
     * rug also has a "Met onderkleed" twin at a surcharge, so including those
     * would push the advertised floor up.
     *
     * @param  array<string, mixed>  $common
     * @param  Collection<int, Product>  $variants
     * @return array<string, mixed>
     */
    private function sizes(array $common, Collection $variants): array
    {
        $rows = $variants->map(fn (Product $variant): array => $this->normalise(
            is_array($variant->values) ? ($variant->values['common'] ?? []) : []
        ));

        $maten = $rows->pluck('maat')
            ->filter(fn ($maat): bool => is_string($maat) && $maat !== '')
            ->unique()
            ->values();

        $standaard = $maten->reject(fn (string $maat): bool => in_array($maat, self::MADE_TO_MEASURE, true));

        $zonderOnderkleed = $rows->filter(
            fn (array $row): bool => ($row['onderkleed'] ?? null) !== 'Met onderkleed'
        );

        $prijzen = $zonderOnderkleed
            ->map(fn (array $row) => $this->price($row, 'prijs'))
            ->filter(fn (?float $prijs): bool => $prijs !== null && $prijs > 0);

        return array_filter([
            'standaardmaten_rechthoekig' => $standaard->reject($this->isRound(...))->values()->all(),
            'standaardmaten_rond'        => $standaard->filter($this->isRound(...))->values()->all(),
            'maatwerk_mogelijk'          => $maten->intersect(self::MADE_TO_MEASURE)->values()->all(),
            'maximale_breedte'           => $this->text($common, 'maximale_breedte_cm'),
            'maximale_lengte'            => $this->text($common, 'maximale_lengte_cm'),
            'levertijd_voorradig'        => $rows->pluck('levertijd_voorradig')->filter()->first(),
            'levertijd_niet_voorradig'   => $rows->pluck('levertijd_niet_voorradig')->filter()->first(),
            'prijs_vanaf'                => $prijzen->min(),
            'prijs_tot'                  => $prijzen->max(),
            'prijs_per_m2'               => $this->price($common, 'prijs_per_m2'),
            'prijs_rond_per_m2'          => $this->price($common, 'prijs_rond_m2'),
        ], fn ($value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * Maximum dimensions only, for the create-form case where no variants exist.
     *
     * @param  array<string, mixed>  $common
     * @return array<string, mixed>
     */
    private function maxDimensions(array $common): array
    {
        return array_filter([
            'maximale_breedte' => $this->text($common, 'maximale_breedte_cm'),
            'maximale_lengte'  => $this->text($common, 'maximale_lengte_cm'),
            'prijs_per_m2'     => $this->price($common, 'prijs_per_m2'),
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return Collection<int, Product>
     */
    private function variants(Product $product): Collection
    {
        return Product::query()
            ->where('parent_id', $product->id)
            ->where('status', 1)
            ->get(['id', 'sku', 'values']);
    }

    /**
     * Colourways are not a variant axis: each colour is its own parent, linked
     * through cross_sells. The count feeds the "verkrijgbaar in N kleuren" line
     * the current texts already carry.
     */
    private function colourwayCount(Product $product): ?int
    {
        $values = $product->values;

        if (is_string($values)) {
            $values = json_decode($values, true);
        }

        $crossSells = is_array($values) ? ($values['associations']['cross_sells'] ?? []) : [];

        return is_array($crossSells) && count($crossSells) > 1 ? count($crossSells) : null;
    }

    /**
     * @return list<string>
     */
    private function categories(Product $product): array
    {
        $values = $product->values;

        if (is_string($values)) {
            $values = json_decode($values, true);
        }

        $categories = is_array($values) ? ($values['categories'] ?? []) : [];

        if (! is_array($categories)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($category): string => is_string($category) ? trim($category) : '',
            $categories
        )));
    }

    /**
     * @param  array<string, mixed>  $common
     * @param  list<string>  $codes
     * @return array<string, string|list<string>>
     */
    private function group(array $common, array $codes): array
    {
        $group = [];

        foreach ($codes as $code) {
            $value = in_array($code, self::MULTI_VALUE, true)
                ? $this->list($common, $code)
                : $this->text($common, $code);

            if ($value !== null && $value !== [] && $value !== '') {
                $group[$code] = $value;
            }
        }

        return $group;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalise(array $values): array
    {
        $clean = [];

        foreach ($values as $code => $value) {
            if (is_string($value)) {
                $value = trim($value);

                if ($value === '' || strtolower($value) === 'null') {
                    continue;
                }
            }

            if ($value === null) {
                continue;
            }

            $clean[$code] = $value;
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function text(array $values, string $code): ?string
    {
        $value = $values[$code] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A pipe-separated attribute split into trimmed tokens.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private function list(array $values, string $code): array
    {
        $raw = $this->text($values, $code);

        if ($raw === null) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode('|', $raw)),
            fn (string $token): bool => $token !== '' && strtolower($token) !== 'null'
        ));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function price(array $values, string $code): ?float
    {
        $value = $values[$code] ?? null;

        if (is_array($value)) {
            $value = $value['EUR'] ?? null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function isRound(string $maat): bool
    {
        return Str::contains(Str::lower($maat), ['rond', 'ø']);
    }
}
