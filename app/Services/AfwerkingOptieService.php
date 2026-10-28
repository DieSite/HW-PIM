<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Decides which edge finishes (randafwerkingen) a maatwerk rug offers and at
 * what price, and shapes that into the payload the shop consumes.
 *
 * The final price cannot be computed here: it depends on the measurements the
 * customer types in on the product page. So the PIM ships the rule for this
 * rug — its choices and their rates, per config/afwerkingen.php — and the shop
 * does the arithmetic.
 */
class AfwerkingOptieService
{
    /**
     * Bumped when the payload shape changes in a way the shop must notice.
     * Version 2: one required choice per rug, per-brand rules, and rates per
     * m² in area bands (`max_m2`).
     */
    public const PAYLOAD_VERSIE = 2;

    /**
     * Whether this parent product offers edge finishes at all.
     */
    public function isBeschikbaar(Product $parent): bool
    {
        return $this->beschikbaar(...$this->scopesVan($parent));
    }

    /**
     * The edge finish rule for this product.
     *
     * @return array<string, mixed>|null
     */
    public function payloadVoor(Product $parent): ?array
    {
        return $this->payload(...$this->scopesVan($parent));
    }

    /**
     * The same payload, built from the array the WooCommerce exporter already
     * holds. Reading it straight from `$item` keeps the export free of the
     * extra queries a model round-trip would cost on every product sync.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    public function payloadVoorItem(array $item): ?array
    {
        $variants = array_map(
            fn ($variant): array => $this->commonVan(Arr::get($variant, 'values')),
            Arr::get($item, 'variants', [])
        );

        return $this->payload($this->commonVan(Arr::get($item, 'values')), $variants);
    }

    /**
     * A rug qualifies when its brand has a rule that covers its quality and it
     * is maatwerk. The manual `afwerking_beschikbaar` override can switch a rug
     * off, or on without a maatwerk variant, but it cannot invent a price: a
     * rug without a matching rule never qualifies, and neither does a wool rug.
     *
     * @param  array<string, mixed>  $parent
     * @param  array<int, array<string, mixed>>  $variants
     */
    private function beschikbaar(array $parent, array $variants): bool
    {
        if (! $this->featureIsIngeschakeld() || $this->heeftUitgeslotenMateriaal($parent, $variants)) {
            return false;
        }

        if ($this->regelVan($parent, $variants) === null) {
            return false;
        }

        $override = strtolower(trim((string) ($parent['afwerking_beschikbaar'] ?? '')));

        if ($override === 'nee') {
            return false;
        }

        return $override === 'ja' || $this->heeftMaatwerk($parent, $variants);
    }

    /**
     * Returns null when the product offers nothing; the exporter then sends an
     * empty meta value, which the shop reads as "no options".
     *
     * @param  array<string, mixed>  $parent
     * @param  array<int, array<string, mixed>>  $variants
     * @return array<string, mixed>|null
     */
    private function payload(array $parent, array $variants): ?array
    {
        if (! $this->beschikbaar($parent, $variants)) {
            return null;
        }

        [$merkRegel, $kwaliteit] = $this->regelVan($parent, $variants);

        return [
            'versie'         => self::PAYLOAD_VERSIE,
            'valuta'         => 'EUR',
            'btw_inbegrepen' => true,
            'opties'         => [$this->formatOptie($merkRegel, $kwaliteit)],
        ];
    }

    /**
     * The single required choice for this rug. Its rates are a surcharge on
     * top of the rug price, which never changes: per metre of perimeter, or per
     * m² in area bands. Rates given as list prices per m² (Desso) become the
     * difference with the base choice, the finish our own price already has.
     *
     * @param  array<string, mixed>  $merkRegel
     * @param  array<string, mixed>  $kwaliteit
     * @return array<string, mixed>
     */
    private function formatOptie(array $merkRegel, array $kwaliteit): array
    {
        $basis = $kwaliteit['keuzes'][$merkRegel['basiskeuze'] ?? ''] ?? null;

        $keuzes = [];

        foreach ($kwaliteit['keuzes'] ?? [] as $code => $keuze) {
            $keuzes[] = [
                'code'     => $code,
                'label'    => $keuze['label'] ?? $code,
                'tarieven' => isset($keuze['m2'])
                    ? $this->m2Tarieven($keuze['m2'], $basis['m2'] ?? [])
                    : array_map(fn (array $tarief): array => [
                        'max_lengte_cm' => $tarief['max_lengte_cm'] ?? null,
                        'tarief'        => (float) $tarief['tarief'],
                    ], $keuze['tarieven'] ?? []),
            ];
        }

        return [
            'code'      => 'randafwerking',
            'label'     => 'Randafwerking',
            'verplicht' => true,
            'eenheid'   => $merkRegel['eenheid'] ?? 'omtrek_m',
            'keuzes'    => $keuzes,
            'toeslagen' => [],
        ];
    }

    /**
     * The surcharge per m² for each area band: the list price of this choice
     * minus that of the base choice in the same band.
     *
     * @param  list<float|int>  $prijzen
     * @param  list<float|int>  $basisPrijzen
     * @return list<array{max_m2: float|null, tarief: float}>
     */
    private function m2Tarieven(array $prijzen, array $basisPrijzen): array
    {
        $banden = config('afwerkingen.m2_banden', []);
        $basisPrijzen = array_values($basisPrijzen);
        $tarieven = [];

        foreach (array_values($prijzen) as $index => $prijs) {
            $tarieven[] = [
                'max_m2' => isset($banden[$index]) ? (float) $banden[$index] : null,
                'tarief' => round((float) $prijs - (float) ($basisPrijzen[$index] ?? $prijs), 2),
            ];
        }

        return $tarieven;
    }

    /**
     * The brand rule and the quality within it that cover this rug, or null.
     *
     * @param  array<string, mixed>  $parent
     * @param  array<int, array<string, mixed>>  $variants
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    private function regelVan(array $parent, array $variants): ?array
    {
        $merk = $this->merkVan($parent, $variants);

        if ($merk === null) {
            return null;
        }

        $delen = array_map(fn (string $deel): string => strtolower(trim($deel)), explode('|', $merk));

        foreach (config('afwerkingen.merken', []) as $naam => $merkRegel) {
            if (! in_array(strtolower($naam), $delen, true) || ! $this->merkIsIngeschakeld($naam)) {
                continue;
            }

            foreach ($merkRegel['kwaliteiten'] ?? [] as $kwaliteit) {
                if ($this->kwaliteitPast($kwaliteit['match'] ?? [], $parent, $variants)) {
                    return [$merkRegel, $kwaliteit];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $match
     * @param  array<string, mixed>  $parent
     * @param  array<int, array<string, mixed>>  $variants
     */
    private function kwaliteitPast(array $match, array $parent, array $variants): bool
    {
        if (isset($match['collectie'])) {
            $collectie = strtolower(trim((string) $this->eersteWaarde('collectie', $parent, $variants)));

            if (! in_array($collectie, array_map('strtolower', $match['collectie']), true)) {
                return false;
            }
        }

        if (isset($match['productnaam_begint_met'])) {
            $naam = (string) $this->eersteWaarde('productnaam', $parent, $variants);

            if (! Str::startsWith(strtolower($naam), array_map('strtolower', $match['productnaam_begint_met']))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the rug is made of a material that rules out finishing options.
     * `materiaal` is a pipe list whose parts can be phrases ("Wol en katoen"),
     * so each part is matched on the whole word. Like the brand, the variants
     * count too: the material is not filled on every parent.
     *
     * @param  array<string, mixed>  $parent
     * @param  array<int, array<string, mixed>>  $variants
     */
    private function heeftUitgeslotenMateriaal(array $parent, array $variants): bool
    {
        $uitgesloten = config('afwerkingen.uitgesloten_materialen', []);

        foreach ([$parent, ...$variants] as $scope) {
            foreach (explode('|', (string) ($scope['materiaal'] ?? '')) as $deel) {
                foreach ($uitgesloten as $materiaal) {
                    if (preg_match('/\b'.preg_quote($materiaal, '/').'\b/iu', $deel)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function featureIsIngeschakeld(): bool
    {
        return $this->vlag('general.afwerkingen.settings.enabled');
    }

    private function merkIsIngeschakeld(string $merk): bool
    {
        return $this->vlag('general.afwerkingen.merken.'.Str::slug($merk, '_').'_enabled');
    }

    /**
     * A boolean admin setting, defaulting to on when it was never saved.
     */
    private function vlag(string $key): bool
    {
        $value = core()->getConfigData($key);

        if ($value === null || $value === '') {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The brand, falling back to the first variant that carries one — merk is
     * normally filled on the parent, but not on every product.
     *
     * @param  array<string, mixed>  $parent
     * @param  array<int, array<string, mixed>>  $variants
     */
    private function merkVan(array $parent, array $variants): ?string
    {
        $merk = $this->eersteWaarde('merk', $parent, $variants);

        return $merk === null ? null : (string) $merk;
    }

    /**
     * The first non-empty value of an attribute, parent first.
     *
     * @param  array<string, mixed>  $parent
     * @param  array<int, array<string, mixed>>  $variants
     */
    private function eersteWaarde(string $attribuut, array $parent, array $variants): mixed
    {
        foreach ([$parent, ...$variants] as $scope) {
            $waarde = $scope[$attribuut] ?? null;

            if ($waarde !== null && $waarde !== '' && $waarde !== 'null') {
                return $waarde;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $parent
     * @param  array<int, array<string, mixed>>  $variants
     */
    private function heeftMaatwerk(array $parent, array $variants): bool
    {
        /**
         * A simple product without variants can be maatwerk in its own right,
         * so the parent's own maat counts too.
         */
        foreach ([$parent, ...$variants] as $scope) {
            if (str_contains(strtolower((string) ($scope['maat'] ?? '')), 'maatwerk')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The parent's and variants' `common` scopes, in the shape the private
     * helpers work on.
     *
     * @return array{array<string, mixed>, array<int, array<string, mixed>>}
     */
    private function scopesVan(Product $parent): array
    {
        /**
         * The relation resolves through Webkul's product proxy, so the items
         * are not necessarily App\Models\Product — hence no type hint here.
         */
        $variants = $parent->variants
            ->map(fn ($variant): array => $this->commonVan($variant->values))
            ->all();

        return [$this->commonVan($parent->values), $variants];
    }

    /**
     * The `common` scope of a `values` blob, tolerating the double-encoded
     * column some legacy rows still carry.
     *
     * @return array<string, mixed>
     */
    private function commonVan(mixed $values): array
    {
        if (is_string($values)) {
            $values = json_decode($values, true);
        }

        if (! is_array($values)) {
            return [];
        }

        $common = $values['common'] ?? [];

        return is_array($common) ? $common : [];
    }
}
