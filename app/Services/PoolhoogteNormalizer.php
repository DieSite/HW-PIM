<?php

namespace App\Services;

use Webkul\Product\Models\Product as WebkulProduct;

/**
 * Writes values.common.poolhoogte in one format, "<number> mm" with a decimal
 * comma ("25 mm", "4,3 mm"), so the shop gets one filter term per height
 * instead of "25 mm" next to "25mm".
 *
 * Accepts a missing or doubled space, any case, a decimal point, a value in
 * cm (converted to mm) and a bare number (read as mm). Anything else is left
 * as it is. Every product save applies it (see AppServiceProvider);
 * products:normalize-poolhoogte fixes the existing values.
 */
class PoolhoogteNormalizer
{
    public const ATTRIBUTE = 'poolhoogte';

    private const PATTERN = '/^(\d+)(?:[.,](\d+))?\s*(mm|cm)?\.?$/i';

    /**
     * The normalised value, or the value unchanged when it cannot be read.
     */
    public function normalize(mixed $value): mixed
    {
        $millimetres = $this->millimetres($value);

        if ($millimetres === null) {
            return $value;
        }

        return rtrim(rtrim(number_format($millimetres, 2, ',', ''), '0'), ',').' mm';
    }

    /**
     * The height in millimetres ("4,3 mm" → 4.3, "1,5 cm" → 15.0), or null
     * when the value cannot be read as a height.
     */
    public function millimetres(mixed $value): ?float
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        if (! preg_match(self::PATTERN, trim(preg_replace('/\s+/u', ' ', (string) $value)), $matches)) {
            return null;
        }

        $millimetres = (float) ($matches[1].'.'.($matches[2] ?? '0'));

        return strtolower($matches[3] ?? 'mm') === 'cm' ? $millimetres * 10 : $millimetres;
    }

    /**
     * Whether the value can be read as a height (and so be normalised).
     */
    public function isReadable(mixed $value): bool
    {
        return $this->millimetres($value) !== null;
    }

    /**
     * Normalise the product's poolhoogte in place. Returns whether it changed.
     */
    public function applyTo(WebkulProduct $product): bool
    {
        $values = $product->values;

        if (! is_array($values) || ! array_key_exists(self::ATTRIBUTE, $values['common'] ?? [])) {
            return false;
        }

        $current = $values['common'][self::ATTRIBUTE];
        $normalized = $this->normalize($current);

        if ($normalized === $current) {
            return false;
        }

        $values['common'][self::ATTRIBUTE] = $normalized;
        $product->values = $values;

        return true;
    }
}
