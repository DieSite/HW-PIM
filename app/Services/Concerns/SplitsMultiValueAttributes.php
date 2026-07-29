<?php

namespace App\Services\Concerns;

trait SplitsMultiValueAttributes
{
    /**
     * Split a multiselect attribute value (`kleuren`, `materiaal`, …) into its
     * separate labels.
     *
     * The PIM stores these either as an array or as one string joined with "|"
     * or ", " — several rugs really do carry more than one colour
     * ("Oranje, Geel"). Casting such a value straight to string yields the
     * literal "Array", so every consumer must go through here.
     *
     * @return string[]
     */
    protected function splitMultiValue(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(
                array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $raw),
                fn ($v) => $v !== ''
            ));
        }

        if (! is_scalar($raw)) {
            return [];
        }

        $raw = (string) $raw;

        if ($raw === '') {
            return [];
        }

        if (str_contains($raw, '|')) {
            return array_values(array_filter(array_map('trim', explode('|', $raw))));
        }

        return array_values(array_filter(array_map('trim', explode(', ', $raw))));
    }
}
