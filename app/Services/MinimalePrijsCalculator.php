<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Str;

class MinimalePrijsCalculator
{
    /**
     * The price of the smallest custom size a customer can order, 100 x 100 cm.
     *
     * The shop prices maatwerk as `max(cm² / 10000 * rate, minimale-prijs)`,
     * where the rate is the maatwerk variant's own price per m² — round custom
     * sizes bill the full bounding square, so Ø 100 cm costs the same. Either
     * way 100 x 100 cm is exactly one square metre, so the 100 x 100 price IS
     * the m² rate.
     *
     * Returns the price formatted like the other price values (two decimals,
     * dot separator), or null when the variant carries no m² rate at all.
     */
    public function hundredByHundredPrice(Product $product): ?string
    {
        $common = $this->common($product);

        foreach ($this->rateCodes($common) as $code) {
            $value = $common[$code]['EUR'] ?? null;

            if ($value === null || $value === '' || ! is_numeric($value) || (float) $value <= 0) {
                continue;
            }

            return number_format((float) $value, 2, '.', '');
        }

        return null;
    }

    /**
     * The attributes that can hold the m² rate, best source first: the variant
     * price is what WooCommerce actually calculates with, the legacy per-m²
     * attributes only serve variants whose price was never filled.
     *
     * @param  array<string, mixed>  $common
     * @return array<int, string>
     */
    private function rateCodes(array $common): array
    {
        $maat = trim((string) ($common['maat'] ?? ''));

        return Str::contains($maat, 'rond', ignoreCase: true)
            ? ['prijs', 'prijs_rond_m2', 'prijs_per_m2']
            : ['prijs', 'prijs_per_m2', 'prijs_rond_m2'];
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
