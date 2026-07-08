<?php

namespace App\Services\ProductImageEditor\Concerns;

trait HandlesDamValues
{
    /**
     * @return array<string, mixed>
     */
    protected function normalizeValues(mixed $values): array
    {
        if (is_string($values)) {
            $values = json_decode($values, true);
        }

        $values = is_array($values) ? $values : [];
        $values['common'] = $values['common'] ?? [];

        return $values;
    }

    /**
     * Parse a DAM value (comma-separated string or array) into a clean list of
     * integer asset ids, dropping empties.
     *
     * @return array<int, int>
     */
    protected function assetIdList(mixed $value): array
    {
        if (is_array($value)) {
            $value = implode(',', $value);
        }

        return array_values(array_filter(array_map(
            static fn ($id): int => (int) trim((string) $id),
            explode(',', (string) $value),
        )));
    }
}
