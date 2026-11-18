<?php

namespace App\Enums;

use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\Product;

/**
 * What a product is, decided by its attribute family. Everything that is not
 * in the cushion family (config `cushions.family_code`) is a rug, so the
 * existing `hw` and `desso_vloerkleden` families keep their behaviour.
 */
enum ProductKind: string
{
    case Rug = 'rug';
    case Cushion = 'cushion';

    public static function of(Product $product): self
    {
        return self::fromFamilyId($product->attribute_family_id);
    }

    public static function fromFamilyId(int|string|null $familyId): self
    {
        $cushionFamilyId = self::cushionFamilyId();

        return $cushionFamilyId !== null && (int) $familyId === $cushionFamilyId ? self::Cushion : self::Rug;
    }

    /**
     * The id of the cushion family, looked up once per request or job.
     */
    public static function cushionFamilyId(): ?int
    {
        return once(function (): ?int {
            $id = DB::table('attribute_families')->where('code', (string) config('cushions.family_code'))->value('id');

            return $id === null ? null : (int) $id;
        });
    }
}
