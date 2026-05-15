<?php

namespace App\Services\Bol;

use App\Models\BolComCredential;
use App\Models\BolEconomicOperator;
use Webkul\Product\Models\Product;

/**
 * Resolves which economic operator UUID to attach to a given product.
 *
 * Lookup order:
 *  1. Explicit override on `products.bol_economic_operator_id`.
 *  2. Operator whose `name` matches the product's brand (`values.common.merk`),
 *     case-insensitive, within this credential.
 *  3. null — Bol will reject or warn.
 */
class BolEconomicOperatorResolver
{
    public function resolve(Product $product, BolComCredential $credential): ?string
    {
        $override = $product->bol_economic_operator_id ?? null;
        if (is_string($override) && $override !== '') {
            return $override;
        }

        $brand = $product->parent?->values['common']['merk']
            ?? $product->values['common']['merk']
            ?? null;

        if (! is_string($brand) || trim($brand) === '') {
            return null;
        }

        $operator = BolEconomicOperator::query()
            ->where('bol_com_credential_id', $credential->id)
            ->whereRaw('LOWER(name) = ?', [strtolower(trim($brand))])
            ->first();

        return $operator?->bol_operator_id;
    }
}
