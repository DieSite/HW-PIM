<?php

namespace App\Services\Bol;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\Product\Models\Product;

/**
 * Builds Bol.com Retailer API v10 request payloads from product values.
 *
 * Everything that used to live in BolComProductService::buildContentData() and
 * ::buildProductData() lives here, but typed and contract-checked.
 *
 * The output shapes match docs/bol-api-spec/retailer-v10.json.
 */
class BolPayloadBuilder
{
    private const STOCK_SOURCES = [
        'voorraad_eurogros',
        'voorraad_5_korting_handmatig',
        'voorraad_hw_5_korting',
    ];

    private const STOCK_MAX = 999;

    public function offer(Product $product, string $deliveryCode): array
    {
        $common = $product->values['common'] ?? [];

        return [
            'ean'                 => (string) ($common['ean'] ?? ''),
            'condition'           => ['name' => 'NEW'],
            'reference'           => (string) $product->sku,
            'onHoldByRetailer'    => false,
            'unknownProductTitle' => (string) ($common['productnaam'] ?? ''),
            'pricing'             => [
                'bundlePrices' => [
                    ['quantity' => 1, 'unitPrice' => $this->price($product)],
                ],
            ],
            'stock'      => $this->stock($common),
            'fulfilment' => ['method' => 'FBR', 'deliveryCode' => $deliveryCode],
        ];
    }

    public function updateOffer(Product $product, string $deliveryCode): array
    {
        $common = $product->values['common'] ?? [];

        return [
            'reference'           => (string) $product->sku,
            'onHoldByRetailer'    => false,
            'unknownProductTitle' => (string) ($common['productnaam'] ?? ''),
            'fulfilment'          => ['method' => 'FBR', 'deliveryCode' => $deliveryCode],
        ];
    }

    public function updatePrice(Product $product): array
    {
        return [
            'pricing' => [
                'bundlePrices' => [
                    ['quantity' => 1, 'unitPrice' => $this->price($product)],
                ],
            ],
        ];
    }

    public function updateStock(Product $product): array
    {
        return $this->stock($product->values['common'] ?? []);
    }

    public function content(Product $product): array
    {
        $common = $product->values['common'] ?? [];
        $parentCommon = $product->parent?->values['common'] ?? [];

        $ean = (string) ($common['ean'] ?? '');
        $title = (string) ($common['productnaam'] ?? '');
        $description = (string) ($parentCommon['beschrijving_l'] ?? '');
        $brand = (string) ($parentCommon['merk'] ?? '');

        [$width, $length, $shape] = $this->dimensions($common, $parentCommon);
        $pileType = $this->pileType($parentCommon);
        $colors = $this->splitMultiValue($parentCommon['kleuren'] ?? '');
        $materials = $this->splitMultiValue($parentCommon['materiaal'] ?? '');

        $attributes = [];

        $attributes[] = $this->attr('EAN', $ean);
        $attributes[] = $this->attr('Name', $title);
        if ($description !== '') {
            $attributes[] = $this->attr('Description', $description);
        }
        $attributes[] = $this->attr('Number of Items in Pack', '1', 'unece.unit.EA');
        $attributes[] = $this->attr('Type of Rug', 'Vloerkleed');
        $attributes[] = $this->attr('Pile type', $pileType);
        $attributes[] = $this->attr('GPC Code', '14176');
        $attributes[] = $this->attr('Indoor or Outdoor', 'Voor binnen');

        if ($colors !== []) {
            $attributes[] = $this->attrMulti('Colour', $colors);
            $attributes[] = $this->attrMulti('Colour Group', $colors);
        }

        if ($width !== '') {
            $attributes[] = $this->attr('Product Width', $width, 'cm');
        }
        if ($length !== '') {
            $attributes[] = $this->attr('Product Length', $length, 'cm');
        }

        if ($materials !== []) {
            $attributes[] = $this->attrMulti('Material', $materials);
        }
        if ($shape !== '') {
            $attributes[] = $this->attr('Shape', $shape);
        }
        if ($brand !== '') {
            $attributes[] = $this->attr('Brand', $brand);
        }

        $attributes = array_values(array_filter($attributes, fn ($a) => $a !== null));

        $payload = [
            'language'   => 'nl',
            'attributes' => $attributes,
        ];

        $assets = $this->assets($parentCommon);
        if ($assets !== []) {
            $payload['assets'] = $assets;
        }

        return $payload;
    }

    /**
     * Single-value attribute, optionally with a unitId.
     * Returns null if value is empty (so empty attributes don't end up in the payload).
     */
    private function attr(string $id, string $value, ?string $unitId = null): ?array
    {
        if ($value === '') {
            return null;
        }

        $entry = ['value' => $value];
        if ($unitId !== null) {
            $entry['unitId'] = $unitId;
        }

        return ['id' => $id, 'values' => [$entry]];
    }

    /**
     * Multi-value attribute (e.g. Colour, Material).
     *
     * @param  string[]  $values
     */
    private function attrMulti(string $id, array $values): ?array
    {
        $entries = array_values(array_filter(array_map(
            fn ($v) => is_string($v) && trim($v) !== '' ? ['value' => trim($v)] : null,
            $values,
        )));

        if ($entries === []) {
            return null;
        }

        return ['id' => $id, 'values' => $entries];
    }

    private function stock(array $common): array
    {
        $stock = 0;
        foreach (self::STOCK_SOURCES as $source) {
            $stock += (int) ($common[$source] ?? 0);
        }

        return [
            'amount'            => max(0, min(self::STOCK_MAX, $stock)),
            'managedByRetailer' => true,
        ];
    }

    private function price(Product $product): float
    {
        $common = $product->values['common'] ?? [];

        if ($product->bol_price_override) {
            $price = (float) $product->bol_price_override;
        } else {
            $priceData = $common['prijs'] ?? 0;
            $price = is_array($priceData) ? (float) ($priceData['EUR'] ?? 0) : 0.0;

            $parentCommon = $product->parent?->values['common'] ?? [];
            if (! empty($parentCommon['merk'])) {
                $snake = Str::snake((string) $parentCommon['merk']);
                $discount = (float) config("bolcom.bol_discounts.$snake", 1);
                $price *= $discount;
            }
        }

        return (float) number_format($price, 2, '.', '');
    }

    private function pileType(array $parentCommon): string
    {
        $raw = $parentCommon['poolhoogte'] ?? '';
        $digits = (int) preg_replace('/\D/', '', (string) $raw);

        return $digits > 15 ? 'Hoogpolig' : 'Laagpolig';
    }

    /**
     * @return array{0:string,1:string,2:string} [width, length, shape]
     */
    private function dimensions(array $common, array $parentCommon): array
    {
        $maat = (string) ($common['maat'] ?? '');

        if (Str::contains($maat, ['Maatwerk', 'Afwijkende afmetingen'])) {
            // Validator should have rejected these earlier; keep the builder pure.
            return ['', '', ''];
        }

        if (str_contains($maat, 'x')) {
            [$width, $length] = explode('x', $maat, 2);
        } else {
            $roundSize = Str::remove('Rond ', $maat);
            [$width, $length] = [$roundSize, $roundSize];
        }

        $width = (string) preg_replace('/\D/', '', $width);
        $length = (string) preg_replace('/\D/', '', $length);

        $shape = match (strtolower((string) ($parentCommon['vorm'] ?? ''))) {
            'oval'      => 'Ovaal',
            'rechthoek' => $width !== '' && $width === $length ? 'Vierkant' : 'Rechthoek',
            'rond'      => 'Rond',
            default     => 'Overig',
        };

        return [$width, $length, $shape];
    }

    /**
     * @return string[]
     */
    private function splitMultiValue(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw), fn ($v) => trim($v) !== ''));
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

    private function assets(array $parentCommon): array
    {
        $raw = $parentCommon['afbeelding_zonder_logo']
            ?? $parentCommon['afbeelding']
            ?? null;

        $images = match (true) {
            is_array($raw)                 => $raw,
            is_string($raw) && $raw !== '' => explode(',', $raw),
            default                        => [],
        };

        $assets = [];
        foreach ($images as $image) {
            $image = is_string($image) ? trim($image) : '';
            if ($image === '') {
                continue;
            }

            $assets[] = [
                'url'    => Storage::disk('private')->url($image),
                'labels' => [$assets === [] ? 'PRIMARY' : 'DETAIL'],
            ];
        }

        return $assets;
    }
}
