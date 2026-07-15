<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;

class CompetitorCatalogExporter
{
    /**
     * Write the competitor-scraper catalog CSV straight from the product
     * database to the given path and return the number of rows written.
     *
     * Format (no header, comma-separated), matching what
     * competitor-analysis/catalog-volledig/catalog.js expects:
     *   SKU, Merk, Model, Maat ("200 cm x 290 cm" or "Maatwerk"), Prijs
     *
     * One row per variant product (every product with a parent). The brand
     * lives on the parent; the model, size and price on the variant.
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

        return implode(',', [
            $this->clean((string) $variant->sku),
            $this->clean((string) $merk),
            $this->clean((string) $model),
            $this->clean((string) $maat),
            $this->clean((string) $prijs),
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
