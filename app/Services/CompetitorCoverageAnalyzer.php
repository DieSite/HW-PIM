<?php

namespace App\Services;

use App\Models\CompetitorPrice;
use App\Models\Product;

/**
 * Werkt uit welke kleden buiten de concurrentie-analyse vallen, en waarom.
 *
 * De analyse draait op varianten: de scraper krijgt per variant een regel
 * (merk, model, maat) en koppelt daar een concurrentprijs aan. Valt een
 * variant daarbuiten, dan blijft zijn prijs op de adviesverkoopprijs staan —
 * zonder dat iemand dat ziet. Deze klasse maakt die stille groep zichtbaar en
 * noemt per kleed de reden, zodat te zien is wat data-onderhoud oplost
 * (ontbrekend merk, lege maat) en wat er per definitie buiten valt (maatwerk,
 * de met-onderkleed-bundel).
 *
 * Per kleed wordt één reden gerapporteerd: de meest blokkerende. Wat er
 * structureel buiten valt (de met-onderkleed-bundel, maatwerk) komt daarbij
 * vóór wat data-onderhoud oplost — een maatwerkkleed een merk geven maakt het
 * nog steeds niet koppelbaar, dus "Maatwerk" is dan het eerlijke antwoord.
 *
 * @see \App\Services\CompetitorCatalogExporter  bouwt de catalogus-CSV
 * @see \App\Services\CompetitorPricingService   rekent de prijs door
 */
class CompetitorCoverageAnalyzer
{
    public const REASON_UNDERLAY = 'Met onderkleed';

    public const REASON_ADVIES = 'Geen adviesverkoopprijs';

    public const REASON_BRAND = 'Geen merk';

    public const REASON_MODEL = 'Geen modelnaam';

    public const REASON_SIZE = 'Geen bruikbare maat';

    public const REASON_MAATWERK = 'Maatwerk';

    public const REASON_NO_MATCH = 'Geen concurrent gevonden';

    /**
     * Wat elke reden betekent, in de taal van wie het rapport leest.
     *
     * @var array<string, string>
     */
    private const EXPLANATIONS = [
        self::REASON_UNDERLAY => 'Geen concurrent verkoopt het kleed mét onderkleed; de prijs wordt afgeleid van de variant zonder onderkleed.',
        self::REASON_MAATWERK => 'Maatwerk heeft geen vaste afmeting; concurrenten voeren er geen vergelijkbare prijs voor.',
        self::REASON_BRAND    => 'De scraper koppelt op merk + model + maat; zonder merk is er niets om op te zoeken.',
        self::REASON_MODEL    => 'De scraper koppelt op merk + model + maat; zonder modelnaam is er niets om op te zoeken.',
        self::REASON_SIZE     => 'De maat is leeg of niet te lezen als afmeting, dus een concurrentmaat is niet te vergelijken.',
        self::REASON_ADVIES   => 'Zonder adviesverkoopprijs is er geen plafond en geen bodem, dus de prijs wordt nooit herberekend.',
        self::REASON_NO_MATCH => 'Wel meegenomen in de scrape, maar geen enkele concurrent voert dit kleed in deze maat (of niet herkenbaar genoeg om te koppelen).',
    ];

    /**
     * Elk kleed dat niet in de analyse zit, als platte rij.
     *
     * Een generator, geen array: het gaat om tienduizenden varianten en de
     * aanroeper (de Excel-export) schrijft ze streamend weg.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function uncovered(): \Generator
    {
        $covered = $this->coveredSkus();

        $variants = Product::query()
            ->whereNotNull('parent_id')
            ->with('parent:id,sku,values')
            ->select(['id', 'sku', 'status', 'parent_id', 'values'])
            ->lazyById(500);

        foreach ($variants as $variant) {
            $reason = $this->reason($variant, isset($covered[$variant->sku]));

            if ($reason === null) {
                continue;
            }

            yield $this->row($variant, $reason);
        }
    }

    /**
     * De reden waarom dit kleed buiten de analyse valt, of null als het er
     * gewoon in zit.
     */
    public function reason(Product $variant, bool $hasCompetitorPrice): ?string
    {
        $common = $this->common($variant);
        $parentCommon = $variant->parent !== null ? $this->common($variant->parent) : [];

        if (($common['onderkleed'] ?? null) === 'Met onderkleed') {
            return self::REASON_UNDERLAY;
        }

        $maat = trim((string) ($common['maat'] ?? ''));

        if (stripos($maat, 'maatwerk') !== false) {
            return self::REASON_MAATWERK;
        }

        if (! $this->filled($parentCommon['merk'] ?? $common['merk'] ?? null)) {
            return self::REASON_BRAND;
        }

        if (! $this->filled($common['productnaam'] ?? $parentCommon['productnaam'] ?? null)) {
            return self::REASON_MODEL;
        }

        if ($this->parseSize($maat) === null) {
            return self::REASON_SIZE;
        }

        if (! $this->filled($common['adviesverkoopprijs']['EUR'] ?? null)) {
            return self::REASON_ADVIES;
        }

        return $hasCompetitorPrice ? null : self::REASON_NO_MATCH;
    }

    public function explain(string $reason): string
    {
        return self::EXPLANATIONS[$reason] ?? '';
    }

    /**
     * @return array<int, string>
     */
    public function reasons(): array
    {
        return array_keys(self::EXPLANATIONS);
    }

    /**
     * De kolommen van de export, in volgorde.
     *
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'SKU',
            'Parent SKU',
            'Merk',
            'Model',
            'Maat',
            'Onderkleed',
            'Adviesverkoopprijs',
            'Huidige prijs',
            'Status',
            'Reden',
            'Toelichting',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Product $variant, string $reason): array
    {
        $common = $this->common($variant);
        $parentCommon = $variant->parent !== null ? $this->common($variant->parent) : [];

        return [
            'sku'                => (string) $variant->sku,
            'parent_sku'         => (string) ($variant->parent?->sku ?? ''),
            'merk'               => (string) ($parentCommon['merk'] ?? $common['merk'] ?? ''),
            'model'              => (string) ($common['productnaam'] ?? $parentCommon['productnaam'] ?? ''),
            'maat'               => (string) ($common['maat'] ?? ''),
            'onderkleed'         => (string) ($common['onderkleed'] ?? ''),
            'adviesverkoopprijs' => $this->price($common['adviesverkoopprijs']['EUR'] ?? null),
            'prijs'              => $this->price($common['prijs']['EUR'] ?? null),
            'status'             => $variant->status ? 'Actief' : 'Inactief',
            'reden'              => $reason,
            'toelichting'        => $this->explain($reason),
        ];
    }

    /**
     * De SKU's waarvoor een echte concurrentprijs is opgeslagen, als lookup.
     *
     * @return array<string, true>
     */
    private function coveredSkus(): array
    {
        return CompetitorPrice::query()
            ->where('price', '>', 0)
            ->distinct()
            ->pluck('sku')
            ->flip()
            ->map(fn (): bool => true)
            ->all();
    }

    /**
     * Leest de maat zoals de scraper dat doet: een afmeting van 50–600 cm, of
     * een rond kleed met één maat. Wat hier niet doorheen komt, koppelt de
     * scraper ook niet.
     *
     * @see competitor-analysis/catalog-volledig/normalize.js  parseSize()
     *
     * @return array{width: int, height: int}|null
     */
    private function parseSize(string $maat): ?array
    {
        if (preg_match('/(\d+)\s*(?:cm)?\s*[-x×]\s*(\d+)\s*(?:cm)?/iu', $maat, $matches) === 1) {
            $width = (int) $matches[1];
            $height = (int) $matches[2];

            return $this->plausible($width) && $this->plausible($height)
                ? ['width' => $width, 'height' => $height]
                : null;
        }

        $round = preg_match('/(?:\brond\b|\bronde\b|\bround\b|ø|⌀)[^0-9]{0,10}(\d{2,3})/iu', $maat, $matches) === 1
            || preg_match('/(\d{2,3})\s*(?:cm)?\s*(?:\brond\b|\bronde\b|\bround\b)/iu', $maat, $matches) === 1;

        if ($round && $this->plausible((int) $matches[1])) {
            return ['width' => (int) $matches[1], 'height' => (int) $matches[1]];
        }

        return null;
    }

    private function plausible(int $cm): bool
    {
        return $cm >= 50 && $cm <= 600;
    }

    private function filled(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }

    private function price(mixed $value): ?float
    {
        return $value === null || $value === '' || ! is_numeric($value) ? null : (float) $value;
    }

    /**
     * De `common`-scope van een product, bestand tegen de dubbel-geëncodeerde
     * `values`-kolom die sommige legacy-rijen nog hebben.
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
