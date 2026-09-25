<?php

namespace App\Services\Mcp;

use App\Exceptions\ProductUpsertException;
use App\Mcp\AdminPermission;
use App\Services\DeliveryTimeService;
use App\Services\ProductService;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeFamily;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Product\Models\Product;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Type\AbstractType;
use Webkul\Product\Validator\ProductValuesValidator;
use Webkul\User\Models\Admin;

/**
 * Creates and updates products in bulk for the MCP server. Never deletes.
 *
 * Existing products are patched: only the attributes sent change, everything
 * else is kept. New products are created as a configurable parent, a variant
 * under an existing (or same-batch) parent, or a simple product.
 *
 * Every item runs in its own savepoint, so one bad item does not stop the rest.
 * A dry run executes the whole batch inside a transaction that is rolled back,
 * which reports exactly what a real run would do without writing anything.
 *
 * After a real run the same downstream work as an admin save is started, once
 * per touched product/parent: the after-events (search index, DAM, WooCommerce
 * listener), the WooCommerce parent chain, the Bol sync for Bol-enabled
 * products, and the onderkleed stock copy when a stock field changed.
 */
class ProductUpsertService
{
    public const MAX_ITEMS = 100;

    public const VALUE_SECTIONS = [
        AbstractType::COMMON_VALUES_KEY,
        AbstractType::LOCALE_VALUES_KEY,
        AbstractType::CHANNEL_VALUES_KEY,
        AbstractType::CHANNEL_LOCALE_VALUES_KEY,
        AbstractType::CATEGORY_VALUES_KEY,
    ];

    /**
     * Attribute types the MCP cannot write: asset values are DAM ids that only
     * the admin's image tooling may set.
     */
    public const UNSUPPORTED_ATTRIBUTE_TYPES = ['asset', 'image', 'file', 'gallery'];

    private const STOCK_ATTRIBUTES = [
        'voorraad_eurogros',
        'voorraad_5_korting_handmatig',
        'voorraad_hw_5_korting',
        'uitverkoop_15_korting',
    ];

    private const PREVIEW_LENGTH = 300;

    /** @var array<int, Collection<string, Attribute>> */
    private array $familyAttributes = [];

    public function __construct(
        private ProductRepository $productRepository,
        private AttributeRepository $attributeRepository,
        private ProductValuesValidator $valuesValidator,
        private ProductService $productService,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{dry_run: bool, summary: array<string, int>, results: list<array<string, mixed>>}
     */
    public function upsert(array $items, Admin $admin, bool $dryRun = false): array
    {
        if (count($items) > self::MAX_ITEMS) {
            throw new ProductUpsertException('A batch holds at most '.self::MAX_ITEMS.' items; split it into smaller calls.');
        }

        $results = [];

        /** @var array<int, array{product: Product, action: string, changed: list<string>}> $touched */
        $touched = [];

        DB::beginTransaction();

        try {
            foreach ($this->parentsFirst($items) as $index => $item) {
                $results[$index] = $this->applyItem($item, $admin, $touched);
            }
        } finally {
            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        }

        ksort($results);

        if (! $dryRun) {
            $this->logWrites($admin, $touched);
            $this->dispatchDownstream($touched);
        }

        return [
            'dry_run' => $dryRun,
            'summary' => array_count_values(array_column($results, 'action')) + ['total' => count($results)],
            'results' => array_values($results),
        ];
    }

    /**
     * Parents (items without parent_sku) go first so variants in the same
     * batch find them; the original index is kept for the result order.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function parentsFirst(array $items): array
    {
        $parents = array_filter($items, fn (mixed $item): bool => empty($item['parent_sku']));

        return $parents + array_diff_key($items, $parents);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, array{product: Product, action: string, changed: list<string>}>  $touched
     * @return array<string, mixed>
     */
    private function applyItem(mixed $item, Admin $admin, array &$touched): array
    {
        $sku = is_array($item) && is_string($item['sku'] ?? null) ? trim($item['sku']) : null;

        try {
            if (! $sku) {
                throw new ProductUpsertException('Every item needs a "sku" string.');
            }

            $outcome = DB::transaction(fn (): array => $this->applyToProduct($sku, $item, $admin));
        } catch (ProductUpsertException $e) {
            return ['sku' => $sku, 'action' => 'error', 'errors' => [$e->getMessage()]];
        } catch (ValidationException $e) {
            return ['sku' => $sku, 'action' => 'error', 'errors' => Arr::flatten($e->errors())];
        } catch (Throwable $e) {
            report($e);

            return ['sku' => $sku, 'action' => 'error', 'errors' => ['Unexpected error: '.$e->getMessage()]];
        }

        if ($outcome['action'] !== 'unchanged') {
            $touched[$outcome['product']->id] = [
                'product' => $outcome['product'],
                'action'  => $outcome['action'],
                'changed' => array_keys($outcome['changes']),
            ];
        }

        return [
            'sku'     => $sku,
            'action'  => $outcome['action'],
            'type'    => $outcome['product']->type,
            'parent'  => $outcome['product']->parent?->sku,
            'changes' => $outcome['changes'],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{action: string, product: Product, changes: array<string, array{before: mixed, after: mixed}>}
     */
    private function applyToProduct(string $sku, array $item, Admin $admin): array
    {
        $patch = $this->normalisePatch($sku, $item['values'] ?? []);

        $product = Product::query()->where('sku', $sku)->first();

        if ($product) {
            $this->authorize($admin, 'catalog.products.edit');
            $this->assertIdentityUnchanged($product, $item);

            $action = 'updated';
        } else {
            $this->authorize($admin, 'catalog.products.create');

            $product = $this->createProduct($sku, $item, $patch);

            $action = 'created';
        }

        $this->assertWritable($product, $patch, $action === 'created');

        $this->valuesValidator->validate(data: $this->withoutNulls($patch), productId: $product->id);

        $before = DeliveryTimeService::decodeValues($product->values);
        $after = $this->merge($before, $patch);

        if (array_key_exists('status', $item) && $item['status'] !== null) {
            $product->status = (int) (bool) $item['status'];
        }

        $product->values = $after;

        $changes = $this->diff($before, $after);

        if ($action === 'updated' && $changes === [] && ! $product->isDirty('status')) {
            return ['action' => 'unchanged', 'product' => $product, 'changes' => []];
        }

        $product->save();

        return ['action' => $action, 'product' => $product->refresh(), 'changes' => $changes];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $patch
     */
    private function createProduct(string $sku, array $item, array $patch): Product
    {
        if (! empty($item['parent_sku'])) {
            return $this->createVariant($sku, (string) $item['parent_sku'], $patch);
        }

        $type = $item['type'] ?? null;

        if (! in_array($type, ['simple', 'configurable'], true)) {
            throw new ProductUpsertException("SKU {$sku} does not exist yet. To create it, give \"type\" (simple or configurable) and \"family\", or \"parent_sku\" for a variant.");
        }

        $family = AttributeFamily::query()->where('code', (string) ($item['family'] ?? ''))->first();

        if (! $family) {
            throw new ProductUpsertException('Unknown family "'.($item['family'] ?? '').'". Use get-family-attributes to list the families.');
        }

        $data = [
            'type'                => $type,
            'attribute_family_id' => $family->id,
            'sku'                 => $sku,
        ];

        if ($type === 'configurable') {
            $data['super_attributes'] = $this->validSuperAttributes($family, $item['super_attributes'] ?? []);
        }

        return $this->productRepository->create($data);
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function createVariant(string $sku, string $parentSku, array $patch): Product
    {
        $parent = Product::query()->where('sku', $parentSku)->first();

        if (! $parent || $parent->type !== 'configurable') {
            throw new ProductUpsertException("Parent {$parentSku} does not exist or is not a configurable product.");
        }

        $superAttributes = $parent->super_attributes;
        $combination = [];

        foreach ($superAttributes as $attribute) {
            $value = $patch[AbstractType::COMMON_VALUES_KEY][$attribute->code] ?? null;

            if (! is_string($value) || $value === '') {
                throw new ProductUpsertException("A variant of {$parentSku} needs values.common.{$attribute->code} (one of its option codes).");
            }

            if ($this->attributeRepository->findVariantOption($attribute->code, $value)->isEmpty()) {
                throw new ProductUpsertException("\"{$value}\" is not an option of {$attribute->code}. Use get-family-attributes to see the option codes.");
            }

            $combination[$attribute->code] = $value;
        }

        if (! $this->productRepository->isUniqueVariantForProduct(productId: $parent->id, configAttributes: $combination)) {
            throw new ProductUpsertException("{$parentSku} already has a variant with ".json_encode($combination, JSON_UNESCAPED_UNICODE).'.');
        }

        return app(config('product_types.configurable.class'))->createVariant($parent, $superAttributes, [
            'sku'    => $sku,
            'values' => [AbstractType::COMMON_VALUES_KEY => $combination],
        ]);
    }

    /**
     * @param  mixed  $codes
     * @return list<string>
     */
    private function validSuperAttributes(AttributeFamily $family, mixed $codes): array
    {
        $allowed = $family->getConfigurableAttributes()->pluck('code')->all();
        $codes = is_array($codes) ? array_values(array_unique(array_map('strval', $codes))) : [];

        if ($codes === []) {
            throw new ProductUpsertException('A configurable product needs "super_attributes", chosen from: '.implode(', ', $allowed).'.');
        }

        if ($invalid = array_diff($codes, $allowed)) {
            throw new ProductUpsertException('Not a variant attribute of family '.$family->code.': '.implode(', ', $invalid).'. Choose from: '.implode(', ', $allowed).'.');
        }

        return $codes;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function assertIdentityUnchanged(Product $product, array $item): void
    {
        $familyCode = $product->attribute_family?->code;

        $conflicts = array_filter([
            'type'       => isset($item['type']) && $item['type'] !== $product->type,
            'family'     => isset($item['family']) && $item['family'] !== $familyCode,
            'parent_sku' => isset($item['parent_sku']) && $item['parent_sku'] !== $product->parent?->sku,
        ]);

        if ($conflicts) {
            throw new ProductUpsertException("{$product->sku} already exists; its ".implode(', ', array_keys($conflicts)).' cannot be changed through the MCP.');
        }
    }

    /**
     * Checks every attribute in the patch: part of the product's family, sent
     * in the section that matches its scope, writable through the MCP, not a
     * variant axis of an existing product, and required ones not cleared.
     *
     * @param  array<string, mixed>  $patch
     */
    private function assertWritable(Product $product, array $patch, bool $isNew): void
    {
        $attributes = $this->familyAttributes($product->attribute_family_id);
        $variantAxes = $product->parent_id
            ? $product->parent->super_attributes->pluck('code')->all()
            : $product->super_attributes->pluck('code')->all();

        $errors = [];

        foreach ($this->attributeEntries($patch) as [$section, $path, $code, $value]) {
            $attribute = $attributes->get($code);

            if (! $attribute) {
                $errors[] = "{$path}: {$code} is not an attribute of this product's family.";

                continue;
            }

            if (in_array($attribute->type, self::UNSUPPORTED_ATTRIBUTE_TYPES, true)) {
                $errors[] = "{$path}: {$attribute->type} attributes cannot be set through the MCP.";
            }

            if ($section !== $this->sectionFor($attribute)) {
                $errors[] = "{$path}: {$code} belongs in values.{$this->sectionFor($attribute)}.";
            }

            if (! $isNew && in_array($code, $variantAxes, true)) {
                $errors[] = "{$path}: {$code} is a variant axis and cannot be changed on an existing product.";
            }

            if ($value === null && $attribute->is_required) {
                $errors[] = "{$path}: {$code} is required and cannot be cleared.";
            }
        }

        if ($errors) {
            throw new ProductUpsertException(implode(' ', $errors));
        }
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return list<array{0: string, 1: string, 2: string, 3: mixed}>
     */
    private function attributeEntries(array $patch): array
    {
        $entries = [];

        foreach ($patch[AbstractType::COMMON_VALUES_KEY] ?? [] as $code => $value) {
            if ($code !== 'sku') {
                $entries[] = [AbstractType::COMMON_VALUES_KEY, "common.{$code}", $code, $value];
            }
        }

        foreach ($patch[AbstractType::LOCALE_VALUES_KEY] ?? [] as $locale => $values) {
            foreach ((array) $values as $code => $value) {
                $entries[] = [AbstractType::LOCALE_VALUES_KEY, "locale_specific.{$locale}.{$code}", $code, $value];
            }
        }

        foreach ($patch[AbstractType::CHANNEL_VALUES_KEY] ?? [] as $channel => $values) {
            foreach ((array) $values as $code => $value) {
                $entries[] = [AbstractType::CHANNEL_VALUES_KEY, "channel_specific.{$channel}.{$code}", $code, $value];
            }
        }

        foreach ($patch[AbstractType::CHANNEL_LOCALE_VALUES_KEY] ?? [] as $channel => $locales) {
            foreach ((array) $locales as $locale => $values) {
                foreach ((array) $values as $code => $value) {
                    $entries[] = [AbstractType::CHANNEL_LOCALE_VALUES_KEY, "channel_locale_specific.{$channel}.{$locale}.{$code}", $code, $value];
                }
            }
        }

        return $entries;
    }

    private function sectionFor(Attribute $attribute): string
    {
        return match (true) {
            $attribute->value_per_channel && $attribute->value_per_locale => AbstractType::CHANNEL_LOCALE_VALUES_KEY,
            (bool) $attribute->value_per_channel                           => AbstractType::CHANNEL_VALUES_KEY,
            (bool) $attribute->value_per_locale                            => AbstractType::LOCALE_VALUES_KEY,
            default                                                         => AbstractType::COMMON_VALUES_KEY,
        };
    }

    /**
     * @return Collection<string, Attribute>
     */
    private function familyAttributes(int $familyId): Collection
    {
        return $this->familyAttributes[$familyId] ??= AttributeFamily::query()->findOrFail($familyId)
            ->customAttributes()
            ->get()
            ->keyBy('code');
    }

    /**
     * @return array<string, mixed>
     */
    private function normalisePatch(string $sku, mixed $values): array
    {
        if (! is_array($values)) {
            throw new ProductUpsertException('"values" must be an object.');
        }

        if ($unknown = array_diff(array_keys($values), self::VALUE_SECTIONS)) {
            throw new ProductUpsertException('Unknown values section(s): '.implode(', ', $unknown).'. Allowed: '.implode(', ', self::VALUE_SECTIONS).'.');
        }

        $commonSku = $values[AbstractType::COMMON_VALUES_KEY]['sku'] ?? $sku;

        if ($commonSku !== $sku) {
            throw new ProductUpsertException('values.common.sku must equal the item sku; SKUs cannot be renamed.');
        }

        $values[AbstractType::COMMON_VALUES_KEY] = ['sku' => $sku] + ($values[AbstractType::COMMON_VALUES_KEY] ?? []);

        if (isset($values[AbstractType::CATEGORY_VALUES_KEY])) {
            $values[AbstractType::CATEGORY_VALUES_KEY] = array_values(array_unique(array_map('strval', (array) $values[AbstractType::CATEGORY_VALUES_KEY])));
        }

        return $values;
    }

    /**
     * Applies the patch per attribute: a sent attribute replaces the stored
     * value as a whole (a price keeps no stale currencies), null removes it,
     * anything not sent stays. Categories are replaced when sent.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function merge(array $values, array $patch): array
    {
        foreach ($this->attributeEntries($patch) as [$section, $path, $code, $value]) {
            $key = implode('.', array_slice(explode('.', $path), 0, -1));

            $bucket = Arr::get($values, $key, []);
            $bucket = is_array($bucket) ? $bucket : [];

            if ($value === null) {
                unset($bucket[$code]);
            } else {
                $bucket[$code] = $value;
            }

            Arr::set($values, $key, $bucket);
        }

        $values[AbstractType::COMMON_VALUES_KEY]['sku'] = $patch[AbstractType::COMMON_VALUES_KEY]['sku'];

        if (array_key_exists(AbstractType::CATEGORY_VALUES_KEY, $patch)) {
            $values[AbstractType::CATEGORY_VALUES_KEY] = $patch[AbstractType::CATEGORY_VALUES_KEY];
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function withoutNulls(array $patch): array
    {
        foreach ($patch as $key => $value) {
            if ($value === null) {
                unset($patch[$key]);
            } elseif (is_array($value) && $key !== AbstractType::CATEGORY_VALUES_KEY) {
                $patch[$key] = $this->withoutNulls($value);
            }
        }

        return $patch;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{before: mixed, after: mixed}>
     */
    private function diff(array $before, array $after): array
    {
        $flatBefore = $this->flatten($before);
        $flatAfter = $this->flatten($after);
        $changes = [];

        foreach (array_unique([...array_keys($flatBefore), ...array_keys($flatAfter)]) as $path) {
            $old = $flatBefore[$path] ?? null;
            $new = $flatAfter[$path] ?? null;

            if ($old !== $new) {
                $changes[$path] = ['before' => $this->preview($old), 'after' => $this->preview($new)];
            }
        }

        return $changes;
    }

    /**
     * Flattens values down to attribute level (a price stays one value).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function flatten(array $values): array
    {
        $depths = [
            AbstractType::COMMON_VALUES_KEY         => 1,
            AbstractType::LOCALE_VALUES_KEY         => 2,
            AbstractType::CHANNEL_VALUES_KEY        => 2,
            AbstractType::CHANNEL_LOCALE_VALUES_KEY => 3,
        ];

        $flat = [];

        foreach ($values as $section => $sectionValues) {
            if (! isset($depths[$section])) {
                $flat[$section] = $sectionValues;

                continue;
            }

            $walk = function (mixed $node, string $prefix, int $depth) use (&$walk, &$flat): void {
                if ($depth === 0 || ! is_array($node)) {
                    $flat[$prefix] = $node;

                    return;
                }

                foreach ($node as $key => $child) {
                    $walk($child, "{$prefix}.{$key}", $depth - 1);
                }
            };

            $walk($sectionValues, $section, $depths[$section]);
        }

        return $flat;
    }

    private function preview(mixed $value): mixed
    {
        if (is_string($value) && mb_strlen($value) > self::PREVIEW_LENGTH) {
            return mb_substr($value, 0, self::PREVIEW_LENGTH).'…';
        }

        return $value;
    }

    private function authorize(Admin $admin, string $permission): void
    {
        if (AdminPermission::allows($admin, $permission)) {
            return;
        }

        throw new ProductUpsertException("Your admin role lacks the {$permission} permission.");
    }

    /**
     * @param  array<int, array{product: Product, action: string, changed: list<string>}>  $touched
     */
    private function logWrites(Admin $admin, array $touched): void
    {
        foreach ($touched as $entry) {
            Log::channel('mcp')->info('product '.$entry['action'], [
                'admin'   => $admin->email,
                'sku'     => $entry['product']->sku,
                'changed' => $entry['changed'],
            ]);
        }
    }

    /**
     * @param  array<int, array{product: Product, action: string, changed: list<string>}>  $touched
     */
    private function dispatchDownstream(array $touched): void
    {
        $parentIds = [];

        foreach ($touched as $entry) {
            $product = $entry['product'];

            Event::dispatch($entry['action'] === 'created' ? 'catalog.product.create.after' : 'catalog.product.update.after', $product);

            $parentIds[$product->parent_id ?? $product->id] = true;

            try {
                if ($product->bol_com_sync) {
                    $this->resyncBol($product);
                }

                $stockChanged = array_intersect(
                    array_map(fn (string $path): string => (string) str($path)->after('common.'), $entry['changed']),
                    self::STOCK_ATTRIBUTES
                );

                if ($product->parent_id && $stockChanged) {
                    $this->productService->copyStockValuesOnderkleed($product);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        Product::query()->whereIn('id', array_keys($parentIds))->each(function (Product $parent): void {
            try {
                $this->productService->triggerWCSyncForParent($parent);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * Re-sends a Bol-enabled product with its current Bol settings, exactly
     * as an admin save that leaves the Bol fields untouched would.
     */
    private function resyncBol(Product $product): void
    {
        $credentials = $product->bolComCredentials()->get();

        $this->productService->processBolSync(
            $product,
            true,
            $credentials->pluck('id')->all(),
            $credentials->first()?->pivot?->delivery_code,
            $product->bol_price_override !== null ? (float) $product->bol_price_override : null,
            true
        );
    }
}
