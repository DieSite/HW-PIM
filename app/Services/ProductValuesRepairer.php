<?php

namespace App\Services;

class ProductValuesRepairer
{
    /**
     * A double-encoded `values` column holds a JSON *string* of the JSON object
     * (e.g. "{\"common\":...}") instead of the object itself, which makes every
     * `values->...` query and array access silently fail.
     *
     * Returns the corrected single-encoded JSON when the raw column is
     * double- (or more deeply) encoded, or null when no repair is needed
     * (already healthy, empty, or not repairable).
     */
    public static function fix(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        // A healthy column decodes straight to an array — nothing to do.
        if (is_array($decoded)) {
            return null;
        }

        // Otherwise it is (possibly repeatedly) string-wrapped; unwrap to the object.
        $depth = 0;
        while (is_string($decoded) && $depth < 5) {
            $decoded = json_decode($decoded, true);
            $depth++;
        }

        if (! is_array($decoded)) {
            return null;
        }

        return json_encode($decoded);
    }
}
