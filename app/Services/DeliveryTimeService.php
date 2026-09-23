<?php

namespace App\Services;

use App\Models\DeliveryTimeRule;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Webkul\Core\Models\CoreConfig;
use Webkul\Product\Models\Product as WebkulProduct;

/**
 * Resolves the delivery time of a variant, stored in values.common.levertijd.
 *
 * A rug in the HW showroom (voorraad_hw_5_korting) ships in the showroom time
 * whatever its brand. Otherwise the brand rule of the parent's `merk` applies:
 * its in-stock time when the supplier has it (voorraad_eurogros or
 * voorraad_5_korting_handmatig), its out-of-stock time when not. Without a
 * rule the variant has no delivery time.
 *
 * The rules are edited on Tools → Levertijden. Every variant save recomputes
 * its delivery time (see AppServiceProvider), so stock imports keep it right;
 * ApplyDeliveryTimesJob recomputes all of them after the rules change.
 */
class DeliveryTimeService
{
    public const ATTRIBUTE = 'levertijd';

    public const SHOWROOM_CONFIG_CODE = 'general.delivery_times.showroom';

    /**
     * Until the showroom time is saved on the screen. Saving it empty stores
     * an empty value, which switches the showroom time off.
     */
    public const DEFAULT_SHOWROOM = '2 tot 3 dagen';

    public const CACHE_KEY = 'delivery_time_rules';

    public const SHOWROOM_STOCK = 'voorraad_hw_5_korting';

    public const SUPPLIER_STOCK = ['voorraad_eurogros', 'voorraad_5_korting_handmatig'];

    /**
     * @return array{showroom: ?string, brands: array<string, array{in_stock: ?string, out_of_stock: ?string}>}
     */
    public function rules(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn (): array => [
            'showroom' => self::filled(CoreConfig::query()->where('code', self::SHOWROOM_CONFIG_CODE)->first()?->value ?? self::DEFAULT_SHOWROOM),
            'brands'   => DeliveryTimeRule::query()
                ->get()
                ->mapWithKeys(fn (DeliveryTimeRule $rule): array => [$rule->brand => [
                    'in_stock'     => self::filled($rule->in_stock),
                    'out_of_stock' => self::filled($rule->out_of_stock),
                ]])
                ->all(),
        ]);
    }

    public function forgetRules(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @param  array<string, mixed>  $common  A variant's values.common
     * @param  array{showroom: ?string, brands: array<string, array{in_stock: ?string, out_of_stock: ?string}>}  $rules
     */
    public static function resolve(array $common, ?string $brand, array $rules): ?string
    {
        if ($rules['showroom'] !== null && self::stock($common, [self::SHOWROOM_STOCK]) > 0) {
            return $rules['showroom'];
        }

        $rule = $rules['brands'][$brand] ?? null;

        if ($rule === null) {
            return null;
        }

        return self::stock($common, self::SUPPLIER_STOCK) > 0 ? $rule['in_stock'] : $rule['out_of_stock'];
    }

    /**
     * Put the resolved delivery time in a variant's values, or take it out
     * when none applies.
     *
     * @param  array<string, mixed>  $values
     * @param  array{showroom: ?string, brands: array<string, array{in_stock: ?string, out_of_stock: ?string}>}  $rules
     * @return array<string, mixed>
     */
    public static function withDeliveryTime(array $values, ?string $brand, array $rules): array
    {
        $deliveryTime = self::resolve($values['common'] ?? [], $brand, $rules);

        if ($deliveryTime === null) {
            unset($values['common'][self::ATTRIBUTE]);
        } else {
            $values['common'][self::ATTRIBUTE] = $deliveryTime;
        }

        return $values;
    }

    /**
     * Recompute the delivery time of a variant that is about to be saved.
     * Parents and products whose values are not a decoded array are left alone.
     */
    public function applyTo(WebkulProduct $product): void
    {
        if ($product->parent_id === null || ! is_array($product->values) || ! isset($product->values['common'])) {
            return;
        }

        $values = self::withDeliveryTime($product->values, $this->brandFor($product), $this->rules());

        if ($values !== $product->values) {
            $product->values = $values;
        }
    }

    /**
     * The brand of a variant: its parent's `merk`, or its own when the parent has none.
     */
    public function brandFor(WebkulProduct $product): ?string
    {
        $parentValues = self::decodeValues(Product::query()->whereKey($product->parent_id)->value('values'));

        return self::brandFromValues($parentValues) ?? self::brandFromValues(self::decodeValues($product->values));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function brandFromValues(array $values): ?string
    {
        return self::filled($values['common']['merk'] ?? null);
    }

    /**
     * Product values as an array, also when they were stored double-encoded
     * (a JSON string of the object).
     *
     * @return array<string, mixed>
     */
    public static function decodeValues(mixed $values): array
    {
        while (is_string($values)) {
            $values = json_decode($values, true);
        }

        return is_array($values) ? $values : [];
    }

    /**
     * @param  array<string, mixed>  $common
     * @param  list<string>  $fields
     */
    private static function stock(array $common, array $fields): int
    {
        return array_sum(array_map(fn (string $field): int => (int) ($common[$field] ?? 0), $fields));
    }

    private static function filled(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || $value === 'null' ? null : $value;
    }
}
