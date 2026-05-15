<?php

namespace App\Services\Bol;

use Illuminate\Support\Str;
use Webkul\Product\Models\Product;

class BolProductValidator
{
    private const MAX_TITLE_LENGTH = 200;

    private const FORBIDDEN_MAAT_TOKENS = ['Maatwerk', 'Afwijkende afmetingen'];

    public function validate(Product $product): ValidationResult
    {
        $failures = [];
        $common = $product->values['common'] ?? [];
        $parentCommon = $product->parent?->values['common'] ?? [];

        $normalizedEan = EanNormalizer::normalize($common['ean'] ?? null);
        if ($normalizedEan === null) {
            $failures[] = new ValidationFailure(
                code: 'ean_invalid',
                field: 'ean',
                customerMessage: 'De EAN-code ontbreekt of is ongeldig. Vul een geldige 13-cijferige EAN in om met Bol.com te kunnen synchroniseren.',
            );
        }

        $maat = $common['maat'] ?? null;
        if (empty($maat)) {
            $failures[] = new ValidationFailure(
                code: 'maat_missing',
                field: 'maat',
                customerMessage: 'Je moet een maat invullen om met Bol.com te kunnen synchroniseren.',
            );
        } elseif (Str::contains($maat, self::FORBIDDEN_MAAT_TOKENS)) {
            $failures[] = new ValidationFailure(
                code: 'maat_unsupported',
                field: 'maat',
                customerMessage: 'Maatwerk en afwijkende afmetingen kunnen niet op Bol.com worden aangeboden.',
            );
        }

        $title = $common['productnaam'] ?? null;
        if (empty($title)) {
            $failures[] = new ValidationFailure(
                code: 'title_missing',
                field: 'productnaam',
                customerMessage: 'De productnaam ontbreekt. Vul een productnaam in om op Bol.com te kunnen verkopen.',
            );
        } elseif (is_string($title) && mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            $failures[] = new ValidationFailure(
                code: 'title_too_long',
                field: 'productnaam',
                customerMessage: sprintf('De productnaam is te lang voor Bol.com (max %d tekens).', self::MAX_TITLE_LENGTH),
            );
        }

        $priceData = $common['prijs'] ?? null;
        $price = is_array($priceData) ? (float) ($priceData['EUR'] ?? 0) : 0.0;
        if ($price <= 0) {
            $failures[] = new ValidationFailure(
                code: 'price_missing',
                field: 'prijs',
                customerMessage: 'Vul een verkoopprijs (EUR) in om op Bol.com te kunnen verkopen.',
            );
        }

        if (! $this->hasAnyImage($common, $parentCommon)) {
            $failures[] = new ValidationFailure(
                code: 'images_missing',
                field: 'afbeelding',
                customerMessage: 'Voeg minimaal één productafbeelding toe voordat je synchroniseert met Bol.com.',
            );
        }

        if ($product->parent_id !== null && $product->parent === null) {
            $failures[] = new ValidationFailure(
                code: 'parent_missing',
                field: 'parent_id',
                customerMessage: 'Het bovenliggende product is niet beschikbaar. Controleer de productrelatie.',
            );
        }

        return new ValidationResult(failures: $failures, normalizedEan: $normalizedEan);
    }

    private function hasAnyImage(array $common, array $parentCommon): bool
    {
        foreach (['afbeelding_zonder_logo', 'afbeelding'] as $key) {
            foreach ([$common[$key] ?? null, $parentCommon[$key] ?? null] as $value) {
                if (is_array($value) && $value !== []) {
                    return true;
                }
                if (is_string($value) && trim($value) !== '') {
                    return true;
                }
            }
        }

        return false;
    }
}
