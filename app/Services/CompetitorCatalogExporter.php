<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;

class CompetitorCatalogExporter
{
    use \App\Services\Concerns\SplitsMultiValueAttributes;

    /**
     * Write the competitor-scraper catalog CSV straight from the product
     * database to the given path and return the number of rows written.
     *
     * Format (no header, comma-separated), matching what
     * competitor-analysis/catalog-volledig/catalog.js expects:
     *   SKU, Merk, Model, Maat ("200 cm x 290 cm" or "Maatwerk"), Prijs, Kleuren
     *
     * Kleuren comes from the parent and lets the matcher decide colour variants
     * on evidence: several models ("Oasis 11") carry no colour word in their
     * name, so without it a competitor page that names only a colour
     * ("…-oasis-200x290cm-wit") is undecidable.
     *
     * One row per variant product (every product with a parent). The brand
     * lives on the parent; the model, size and price on the variant.
     *
     * Variants "Met onderkleed" are deliberately LEFT OUT. No competitor sells
     * the rug bundled with an underlay, so scraping a price for them couples a
     * rug-only page to a bundle that costs `config('rugs.underrugs_cost')` more
     * on our own shop — which silently erased that surcharge. Their price is
     * derived from the "Zonder onderkleed" sibling instead
     * (CompetitorPricingService::applyUnderlayPrice).
     */
    public function export(string $path): int
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new \RuntimeException("Unable to open catalog CSV for writing: {$path}");
        }

        $rows = 0;

        try {
            Product::query()
                ->whereNotNull('parent_id')
                ->with('parent:id,values')
                ->select(['id', 'sku', 'parent_id', 'values'])
                ->chunkById(500, function (Collection $variants) use ($handle, &$rows): void {
                    foreach ($variants as $variant) {
                        // Filteren in PHP, niet in SQL: bij de legacy rijen met
                        // een dubbel-geëncodeerde `values`-kolom levert een JSON
                        // path NULL op, en dan glipt de met-onderkleed-variant
                        // er alsnog doorheen. `common()` verdraagt beide vormen.
                        if (($this->common($variant)['onderkleed'] ?? null) === 'Met onderkleed') {
                            continue;
                        }

                        fwrite($handle, $this->line($variant));
                        $rows++;
                    }
                });
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    private function line(Product $variant): string
    {
        $common = $this->common($variant);
        $parentCommon = $variant->parent !== null ? $this->common($variant->parent) : [];

        $merk = $parentCommon['merk'] ?? $common['merk'] ?? '';
        $model = $common['productnaam'] ?? $parentCommon['productnaam'] ?? '';
        $maat = $common['maat'] ?? '';
        $prijs = $common['prijs']['EUR'] ?? $common['adviesverkoopprijs']['EUR'] ?? '';
        $kleuren = implode(' ', $this->splitMultiValue($parentCommon['kleuren'] ?? $common['kleuren'] ?? ''));

        return implode(',', [
            $this->clean((string) $variant->sku),
            $this->clean((string) $merk),
            $this->clean((string) $model),
            $this->clean((string) $maat),
            $this->clean((string) $prijs),
            $this->clean($kleuren),
        ])."\n";
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

    /**
     * The scraper splits each line on "," with no quote handling, so every
     * field must be free of commas and newlines.
     */
    private function clean(string $value): string
    {
        return trim(str_replace([',', "\r", "\n"], ' ', $value));
    }
}
