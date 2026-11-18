<?php

namespace App\Services;

use App\Enums\ProductKind;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Webkul\Product\Models\Product;

/**
 * Keeps the two filling variants of a cushion in step, the way the
 * onderkleed twins of a rug are kept in step:
 *
 * - the "Met vulling" variant's prijs and adviesverkoopprijs are derived:
 *   the "Zonder vulling" variant of the same kussenmaat and kleur plus the
 *   filling surcharge for that size (config `cushions.filling_surcharge`);
 * - stock is shared, because both variants are the same cover.
 */
class CushionFilling
{
    public const WITHOUT = 'Zonder vulling';

    public const WITH = 'Met vulling';

    /**
     * Stock fields a variant shares with its filling twin.
     */
    private const STOCK_ATTRIBUTES = ['voorraad_eurogros', 'voorraad_5_korting_handmatig', 'voorraad_hw_5_korting', 'uitverkoop_15_korting'];

    public function __construct(private ProductService $productService) {}

    /**
     * After one variant was saved: copy its stock to its twin and make sure
     * the "Met vulling" side carries the derived price.
     *
     * @return list<Product> the variants that were changed
     */
    public function syncTwin(Product $variant, bool $withUpdatedEvent = true): array
    {
        if (is_null($variant->parent) || ProductKind::of($variant) !== ProductKind::Cushion) {
            return [];
        }

        $twin = $this->twin($variant);

        if (is_null($twin)) {
            return [];
        }

        $twinValues = $this->productService->productValues($twin);
        $common = $this->productService->commonValues($variant);

        foreach (self::STOCK_ATTRIBUTES as $attribute) {
            $twinValues['common'][$attribute] = $common[$attribute] ?? 0;
        }

        [$base, $withFilling] = $this->filling($variant) === self::WITHOUT ? [$variant, $twin] : [$twin, $variant];

        if ($withFilling->is($twin)) {
            $twinValues['common'] = array_merge($twinValues['common'], $this->derivedPrices($base, $withFilling));
            $this->save($twin, $twinValues, $withUpdatedEvent);

            return [$twin];
        }

        $this->save($twin, $twinValues, $withUpdatedEvent);

        $ownValues = $this->productService->productValues($variant);
        $derived = $this->derivedPrices($base, $variant);

        if ($derived === [] || array_intersect_key($ownValues['common'] ?? [], $derived) == $derived) {
            return [$twin];
        }

        $ownValues['common'] = array_merge($ownValues['common'] ?? [], $derived);
        $this->save($variant, $ownValues, $withUpdatedEvent);

        return [$twin, $variant];
    }

    /**
     * Re-derive every "Met vulling" price of a cushion from its "Zonder
     * vulling" variant, e.g. after the parent was saved with its variants.
     *
     * @return list<Product> the "Met vulling" variants whose price changed
     */
    public function syncPrices(Product $parent, bool $withUpdatedEvent = true): array
    {
        if (ProductKind::of($parent) !== ProductKind::Cushion) {
            return [];
        }

        $changed = [];

        foreach ($parent->variants()->get() as $variant) {
            if ($this->filling($variant) !== self::WITH) {
                continue;
            }

            $base = $this->twin($variant);

            if (is_null($base)) {
                continue;
            }

            $values = $this->productService->productValues($variant);
            $derived = $this->derivedPrices($base, $variant);

            if ($derived === [] || array_intersect_key($values['common'] ?? [], $derived) == $derived) {
                continue;
            }

            $values['common'] = array_merge($values['common'] ?? [], $derived);
            $this->save($variant, $values, $withUpdatedEvent);
            $changed[] = $variant;
        }

        return $changed;
    }

    /**
     * The variant of the same cushion in the same kussenmaat and kleur, with
     * the other filling.
     */
    public function twin(Product $variant): ?Product
    {
        if (is_null($variant->parent)) {
            return null;
        }

        $filling = $this->filling($variant);

        if (is_null($filling)) {
            return null;
        }

        $common = $this->productService->commonValues($variant);
        $other = $filling === self::WITHOUT ? self::WITH : self::WITHOUT;

        return $variant->parent->variants()->get()->first(function (Product $sibling) use ($variant, $common, $other): bool {
            if ($sibling->id === $variant->id) {
                return false;
            }

            $values = $this->productService->commonValues($sibling);

            return $this->filling($sibling) === $other
                && ($values['kussenmaat'] ?? null) === ($common['kussenmaat'] ?? null)
                && ($values['kleur'] ?? null) === ($common['kleur'] ?? null);
        });
    }

    /**
     * The filling surcharge for a variant's kussenmaat, or null when the size
     * is not in the table.
     */
    public function surcharge(Product $variant): ?float
    {
        $size = $this->productService->commonValues($variant)['kussenmaat'] ?? null;
        $surcharge = is_string($size) ? (config('cushions.filling_surcharge')[$size] ?? null) : null;

        return is_numeric($surcharge) ? (float) $surcharge : null;
    }

    /**
     * 'Zonder vulling', 'Met vulling' or null.
     */
    public function filling(Product $variant): ?string
    {
        $filling = $this->productService->commonValues($variant)['vulling'] ?? null;

        return in_array($filling, [self::WITHOUT, self::WITH], true) ? $filling : null;
    }

    /**
     * The prijs and adviesverkoopprijs of $withFilling, derived from $base.
     * Empty when there is nothing to derive (no surcharge for the size, or
     * the base has no price): the variant then keeps what it has.
     *
     * @return array<string, array{EUR: string}>
     */
    private function derivedPrices(Product $base, Product $withFilling): array
    {
        $surcharge = $this->surcharge($withFilling);

        if (is_null($surcharge)) {
            Log::warning('Geen vullingtoeslag voor kussenmaat; met-vulling-variant niet bijgewerkt', [
                'sku'        => $withFilling->sku,
                'kussenmaat' => $this->productService->commonValues($withFilling)['kussenmaat'] ?? null,
            ]);

            return [];
        }

        $common = $this->productService->commonValues($base);
        $derived = [];

        foreach (['prijs', 'adviesverkoopprijs'] as $attribute) {
            $price = $common[$attribute]['EUR'] ?? null;

            if (is_numeric($price)) {
                $derived[$attribute] = ['EUR' => (string) round((float) $price + $surcharge, 2)];
            }
        }

        return $derived;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function save(Product $product, array $values, bool $withUpdatedEvent): void
    {
        $product->values = $values;
        $product->save();

        if ($withUpdatedEvent) {
            Event::dispatch('catalog.product.update.after', $product);
        }
    }
}
