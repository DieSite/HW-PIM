<?php

namespace Webkul\WooCommerce\Presenters;

use Webkul\HistoryControl\Presenters\JsonDataPresenter;

class CredentialPresenter implements JsonDataPresenter
{
    /**
     * Represents value changes for history tracking.
     *
     * @param  mixed  $oldValues  Old values that will be compared.
     * @param  mixed  $newValues  New values to compare against old values.
     * @param  string  $fieldName  Name of the field being tracked.
     * @return array Normalized array of changes for history tracking.
     */
    public static function representValueForHistory(mixed $oldValues, mixed $newValues, string $fieldName): array
    {
        $oldArray = is_string($oldValues) ? json_decode($oldValues, true) : $oldValues;
        $newArray = is_string($newValues) ? json_decode($newValues, true) : $newValues;

        $normalizedData = [];

        $arrayCheckOld = array_filter($oldArray, 'is_array');
        $arrayCheckNew = array_filter($newArray, 'is_array');

        if (count($arrayCheckOld) > 0) {
            $oldArray = array_merge(...array_values($oldArray));
        }

        if (count($arrayCheckNew) > 0) {
            $newArray = array_merge(...array_values($newArray));
        }

        if (empty($oldArray) && empty($newArray)) {
            return $normalizedData;
        }

        $removed = static::calculateDifference($oldArray, $newArray);
        $updated = static::calculateDifference($newArray, $oldArray);

        static::normalizeValues($removed, 'old', $normalizedData);
        static::normalizeValues($updated, 'new', $normalizedData);

        foreach ($normalizedData as $key => $value) {
            $normalizedData[$key] = [
                'name' => $key,
                'old'  => $value['old'] ?? null,
                'new'  => $value['new'] ?? null,
            ];
        }

        return ['versionHistory' => $normalizedData];

    }
}
