<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Webkul\Product\Models\Product;

/**
 * The order sizes are listed in on the product edit screen: standard sizes
 * by SKU in natural order (".2" before ".10"), each "Met onderkleed" twin
 * (".O") right after its base size, and maatwerk last.
 */
class VariantOrder
{
    private const MAATWERK = ['Maatwerk', 'Rond Maatwerk'];

    /**
     * @template TCollection of Collection<int, Product>
     *
     * @param  TCollection  $variants
     * @return TCollection
     */
    public static function sort(Collection $variants): Collection
    {
        return $variants->sort(function (Product $a, Product $b): int {
            [$customA, $baseA, $twinA] = self::key($a);
            [$customB, $baseB, $twinB] = self::key($b);

            return ($customA <=> $customB)
                ?: (strnatcmp($baseA, $baseB) <=> 0)
                ?: ($twinA <=> $twinB);
        })->values();
    }

    /**
     * @return array{0: int, 1: string, 2: int}
     */
    private static function key(Product $variant): array
    {
        $common = DeliveryTimeService::decodeValues($variant->values)['common'] ?? [];
        $isTwin = str_ends_with($variant->sku, '.O');

        return [
            in_array(trim((string) ($common['maat'] ?? '')), self::MAATWERK, true) ? 1 : 0,
            $isTwin ? substr($variant->sku, 0, -2) : $variant->sku,
            $isTwin ? 1 : 0,
        ];
    }
}
