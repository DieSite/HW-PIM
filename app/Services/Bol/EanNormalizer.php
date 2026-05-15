<?php

namespace App\Services\Bol;

class EanNormalizer
{
    /**
     * Normalize an EAN to a 13-digit numeric string accepted by Bol.com.
     * Returns null when the value cannot be coerced into a valid EAN-13.
     */
    public static function normalize(mixed $ean): ?string
    {
        if (! is_scalar($ean)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $ean);

        if ($digits === null || $digits === '') {
            return null;
        }

        if (strlen($digits) > 13) {
            $digits = ltrim($digits, '0');
            $digits = str_pad($digits, 13, '0', STR_PAD_LEFT);
        }

        if (strlen($digits) !== 13) {
            return null;
        }

        return $digits;
    }
}
