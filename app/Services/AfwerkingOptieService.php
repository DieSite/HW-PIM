<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Arr;

/**
 * Decides which finishing options (afwerkingen) a maatwerk rug offers and at
 * what consumer rate, and shapes that into the payload the shop consumes.
 *
 * The rug's final surcharge cannot be computed here: it depends on the
 * measurements the customer types in on the product page. So the PIM ships the
 * rate table and the shop does `omtrek_m × staffeltarief + Σ vaste toeslagen`.
 */
class AfwerkingOptieService
{
    /**
     * Bumped when the payload shape changes in a way the shop must notice.
     */
    public const PAYLOAD_VERSIE = 1;

    /**
     * Whether this parent product offers finishing options at all.
     */
    public function isBeschikbaar(Product $parent): bool
    {
        return $this->beschikbaar(...$this->scopesVan($parent));
    }

    /**
     * The rate table for this product, converted to consumer prices and
     * filtered down to the types that are enabled for its brand.
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
     * The manual `afwerking_beschikbaar` override wins over the brand rule, so
     * a single rug can be switched off, and a rug from a brand that is not on
     * the list can be switched on.
     *
     * @param  array<string, mixed>  $parent
     * @param  array<int, array<string, mixed>>  $variants
     */
    private function beschikbaar(array $parent, array $variants): bool
    {
        if (! $this->featureIsIngeschakeld()) {
            return false;
        }

        $override = strtolower(trim((string) ($parent['afwerking_beschikbaar'] ?? '')));

        if ($override === 'nee') {
            return false;
        }

        if ($override === 'ja') {
            return true;
        }

        return $this->heeftMaatwerk($parent, $variants)
            && $this->merkIsToegestaan($this->merkVan($parent, $variants), config('afwerkingen.merken', []));
    }

    /**
     * Returns null when the product offers nothing — the exporter then leaves
     * the meta off the product entirely.
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

        $merk = $this->merkVan($parent, $variants);

        $opties = [];

        foreach (config('afwerkingen.opties', []) as $code => $optie) {
            if (! $this->typeIsToegestaan($code, $merk)) {
                continue;
            }

            $opties[] = $this->formatOptie($code, $optie);
        }

        if ($opties === []) {
            return null;
        }

        return [
            'versie'          => self::PAYLOAD_VERSIE,
            'valuta'          => 'EUR',
            'btw_inbegrepen'  => true,
            'staffelgrens_cm' => (int) config('afwerkingen.staffelgrens_cm', 400),
            'opties'          => $opties,
        ];
    }

    /**
     * One finishing type, with every inkoop rate converted to a consumer price.
     *
     * @param  array<string, mixed>  $optie
     * @return array<string, mixed>
     */
    private function formatOptie(string $code, array $optie): array
    {
        $keuzes = [];

        foreach ($optie['keuzes'] ?? [] as $keuzeCode => $keuze) {
            $keuzes[] = [
                'code'     => $keuzeCode,
                'label'    => $keuze['label'] ?? $keuzeCode,
                'tarieven' => array_map(fn (array $tarief): array => [
                    'max_lengte_cm' => $tarief['max_lengte_cm'] ?? null,
                    'tarief'        => $this->consumentenPrijs((float) $tarief['inkoop']),
                ], $keuze['tarieven'] ?? []),
            ];
        }

        $formatted = [
            'code'    => $code,
            'label'   => $optie['label'] ?? $code,
            'eenheid' => $optie['eenheid'] ?? 'omtrek_m',
            'keuzes'  => $keuzes,
            /*
             * Vaste toeslagen worden ná de metervermenigvuldiging opgeteld,
             * niet in het metertarief verwerkt. Het expliciete `type` maakt dat
             * aan de shop-kant onmiskenbaar.
             */
            'toeslagen' => array_map(fn (array $toeslag): array => [
                'code'       => $toeslag['code'],
                'label'      => $toeslag['label'],
                'type'       => $toeslag['type'],
                'voorwaarde' => $toeslag['voorwaarde'],
                'bedrag'     => $this->consumentenPrijs((float) $toeslag['inkoop']),
            ], $optie['toeslagen'] ?? []),
        ];

        if (! empty($optie['combineerbaar'])) {
            $formatted['combineerbaar'] = true;
        }

        if (! empty($optie['inclusief_onderkleed'])) {
            $formatted['inclusief_onderkleed'] = true;
        }

        return $formatted;
    }

    /**
     * Inkoop ex BTW → consumentenprijs incl. BTW.
     */
    private function consumentenPrijs(float $inkoop): float
    {
        $btwFactor = 1 + ((float) config('afwerkingen.btw_percentage', 21) / 100);

        return round($inkoop * $this->margeFactor() * $btwFactor, 2);
    }

    private function margeFactor(): float
    {
        $marge = core()->getConfigData('general.afwerkingen.settings.marge_factor');

        if ($marge === null || $marge === '' || ! is_numeric($marge) || (float) $marge <= 0) {
            return (float) config('afwerkingen.standaard_marge', 1.0);
        }

        return (float) $marge;
    }

    private function featureIsIngeschakeld(): bool
    {
        return $this->vlag('general.afwerkingen.settings.enabled');
    }

    /**
     * Whether a finishing type is offered for this brand: it must be switched
     * on, and — if it carries a brand restriction — the brand must be in it.
     */
    private function typeIsToegestaan(string $code, ?string $merk): bool
    {
        if (! $this->vlag("general.afwerkingen.types.{$code}_enabled")) {
            return false;
        }

        $merken = $this->lijst(core()->getConfigData("general.afwerkingen.types.{$code}_merken"));

        if ($merken === []) {
            return true;
        }

        return $this->merkIsToegestaan($merk, $merken);
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
     * The multiselect field type stores its value as a comma-joined string, so
     * both shapes have to be tolerated here.
     *
     * @return list<string>
     */
    private function lijst(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $value), fn (string $item): bool => $item !== ''));
    }

    /**
     * Brands are stored free-text and can be compound: Mart Visser only ever
     * appears as "Mart Visser|Karpi", so both sides are split on the pipe.
     *
     * @param  list<string>  $toegestaan
     */
    private function merkIsToegestaan(?string $merk, array $toegestaan): bool
    {
        if ($merk === null || $merk === '') {
            return false;
        }

        $delen = array_map('strtolower', array_map('trim', explode('|', $merk)));

        foreach ($toegestaan as $kandidaat) {
            if (in_array(strtolower(trim($kandidaat)), $delen, true)) {
                return true;
            }
        }

        return false;
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
        foreach ([$parent, ...$variants] as $scope) {
            $merk = $scope['merk'] ?? null;

            if ($merk !== null && $merk !== '' && $merk !== 'null') {
                return (string) $merk;
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
