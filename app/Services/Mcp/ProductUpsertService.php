<?php

namespace App\Services\Mcp;

use App\Exceptions\ProductUpsertException;
use App\Mcp\AdminPermission;
use App\Services\DeliveryTimeService;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
 * After a real run ProductSyncDispatcher starts the same downstream work as an
 * admin save (after-events, WooCommerce, Bol, onderkleed stock copy).
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
        AbstractType::ASSOCIATION_VALUES_KEY,
    ];

    /**
     * Attribute types upsert-products cannot write: asset values are DAM ids
     * that go through set-product-images (ProductImageService) instead.
     */
    public const UNSUPPORTED_ATTRIBUTE_TYPES = ['asset', 'image', 'file', 'gallery'];

    private const PREVIEW_LENGTH = 300;

    /** @var array<int, Collection<string, Attribute>> */
    private array $familyAttributes = [];

    public function __construct(
        private ProductRepository $productRepository,
        private AttributeRepository $attributeRepository,
        private ProductValuesValidator $valuesValidator,
        private ProductSyncDispatcher $syncDispatcher,
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

        $batchParentSkus = collect($items)
            ->filter(fn (mixed $item): bool => is_array($item) && is_string($item['sku'] ?? null) && empty($item['parent_sku']))
            ->map(fn (array $item): string => trim($item['sku']))
            ->all();

        DB::beginTransaction();

        try {
            foreach ($this->parentsFirst($items) as $index => $item) {
                $results[$index] = $this->applyItem($item, $admin, $touched, $batchParentSkus);
            }

            foreach ($items as $index => $item) {
                if (isset($item['values'][AbstractType::ASSOCIATION_VALUES_KEY]) && $results[$index]['action'] !== 'error') {
                    $results[$index] = $this->applyAssociations($results[$index], $item, $touched);
                }
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
            $this->syncDispatcher->dispatch($touched);
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
     * @param  list<string>  $batchParentSkus
     * @return array<string, mixed>
     */
    private function applyItem(mixed $item, Admin $admin, array &$touched, array $batchParentSkus = []): array
    {
        $sku = is_array($item) && is_string($item['sku'] ?? null) ? trim($item['sku']) : null;

        try {
            if (! $sku) {
                throw new ProductUpsertException('Every item needs a "sku" string.');
            }

            $outcome = DB::transaction(fn (): array => $this->applyToProduct($sku, $item, $admin, $batchParentSkus));
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
     * Cross-sells are validated with the item but written in a second pass,
     * after every product of the batch exists, so the colours of a new
     * collection can refer to each other in one call.
     *
     * @param  array<string, mixed>  $item
     * @param  list<string>  $batchParentSkus
     * @return array{action: string, product: Product, changes: array<string, array{before: mixed, after: mixed}>}
     */
    private function applyToProduct(string $sku, array $item, Admin $admin, array $batchParentSkus = []): array
    {
        $patch = $this->normalisePatch($sku, $item['values'] ?? []);
        $associations = $patch[AbstractType::ASSOCIATION_VALUES_KEY] ?? [];
        unset($patch[AbstractType::ASSOCIATION_VALUES_KEY]);

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

        foreach ($associations as $association => $list) {
            $this->assertAssociation($product, $association, $list, $batchParentSkus);
        }

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
        $axisChanges = [];

        foreach ($this->attributeEntries($patch) as [$section, $path, $code, $value]) {
            $attribute = $attributes->get($code);

            if (! $attribute) {
                $errors[] = "{$path}: {$code} is not an attribute of this product's family.";

                continue;
            }

            if (in_array($attribute->type, self::UNSUPPORTED_ATTRIBUTE_TYPES, true)) {
                $errors[] = $attribute->type === 'asset'
                    ? "{$path}: photos are set with the set-product-images tool, not upsert-products."
                    : "{$path}: {$attribute->type} attributes cannot be set through the MCP.";
            }

            if ($section !== $this->sectionFor($attribute)) {
                $errors[] = "{$path}: {$code} belongs in values.{$this->sectionFor($attribute)}.";
            }

            if (! $isNew && in_array($code, $variantAxes, true)) {
                if ($product->parent_id) {
                    $axisChanges[$code] = $value;
                } else {
                    $errors[] = "{$path}: {$code} is a variant axis; change it on the variants, not on the parent.";
                }
            }

            if ($value === null && $attribute->is_required) {
                $errors[] = "{$path}: {$code} is required and cannot be cleared.";
            }
        }

        if ($axisChanges) {
            array_push($errors, ...$this->axisChangeErrors($product, $variantAxes, $axisChanges));
        }

        if ($errors) {
            throw new ProductUpsertException(implode(' ', $errors));
        }
    }

    /**
     * A variant may move to another axis value (e.g. another maatgroep) when
     * the value is an existing option and no sibling has the new combination.
     *
     * @param  list<string>  $variantAxes
     * @param  array<string, mixed>  $axisChanges
     * @return list<string>
     */
    private function axisChangeErrors(Product $variant, array $variantAxes, array $axisChanges): array
    {
        $errors = [];
        $current = DeliveryTimeService::decodeValues($variant->values)[AbstractType::COMMON_VALUES_KEY] ?? [];
        $combination = [];

        foreach ($variantAxes as $axis) {
            $combination[$axis] = array_key_exists($axis, $axisChanges) ? $axisChanges[$axis] : ($current[$axis] ?? null);
        }

        foreach ($axisChanges as $axis => $value) {
            if (! is_string($value) || $value === '') {
                $errors[] = "common.{$axis}: a variant always needs a {$axis} option.";
            } elseif ($this->attributeRepository->findVariantOption($axis, $value)->isEmpty()) {
                $errors[] = "common.{$axis}: \"{$value}\" is not an option of {$axis}. Add it with add-attribute-options first.";
            }
        }

        if ($errors === [] && ! $this->productRepository->isUniqueVariantForProduct(productId: $variant->parent_id, configAttributes: $combination, variantId: $variant->id)) {
            $sibling = Product::query()->where('parent_id', $variant->parent_id)->whereKeyNot($variant->id)
                ->where(fn ($query) => collect($combination)->each(fn ($value, $axis) => $query->where('values->common->'.$axis, $value)))
                ->value('sku');

            $errors[] = "{$variant->parent->sku} already has a variant with ".json_encode($combination, JSON_UNESCAPED_UNICODE).($sibling ? " ({$sibling})" : '').'; move that one first.';
        }

        return $errors;
    }

    /**
     * Cross-sells and other shapes point at other parent (or standalone)
     * products: the shop links products, not variations.
     *
     * @param  list<string>  $list
     * @param  list<string>  $batchParentSkus
     */
    private function assertAssociation(Product $product, string $association, array $list, array $batchParentSkus): void
    {
        if ($product->parent_id) {
            throw new ProductUpsertException("associations.{$association}: these belong on the parent {$product->parent->sku}, not on a variant.");
        }

        $found = Product::query()->with('parent:id,sku')->whereIn('sku', $list)->get(['id', 'sku', 'parent_id'])->keyBy('sku');
        $errors = [];

        foreach ($list as $sku) {
            $target = $found->get($sku);

            if ($target?->parent_id) {
                $errors[] = "{$sku} is a variant; use its parent {$target->parent->sku}.";
            } elseif (! $target && ! in_array($sku, $batchParentSkus, true)) {
                $errors[] = "{$sku} does not exist.";
            }
        }

        if ($errors) {
            throw new ProductUpsertException("associations.{$association}: ".implode(' ', $errors));
        }
    }

    /**
     * Second pass: writes the association lists of an item whose values were
     * applied. A reference to a batch product that failed is left out and
     * reported. andere_vormen is made mutual on saving (AndereVormenMirror);
     * the other members are synced by the mirror, not reported here.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $item
     * @param  array<int, array{product: Product, action: string, changed: list<string>}>  $touched
     * @return array<string, mixed>
     */
    private function applyAssociations(array $result, array $item, array &$touched): array
    {
        foreach ($this->normalisePatch($result['sku'], $item['values'])[AbstractType::ASSOCIATION_VALUES_KEY] ?? [] as $association => $wanted) {
            $result = $this->applyAssociation($result, $association, $wanted, $touched);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<string>  $wanted
     * @param  array<int, array{product: Product, action: string, changed: list<string>}>  $touched
     * @return array<string, mixed>
     */
    private function applyAssociation(array $result, string $association, array $wanted, array &$touched): array
    {
        $sku = $result['sku'];

        $outcome = DB::transaction(function () use ($sku, $association, $wanted): array {
            $product = Product::query()->where('sku', $sku)->firstOrFail();
            $existing = Product::query()->whereIn('sku', $wanted)->whereNull('parent_id')->pluck('sku')->all();

            $before = DeliveryTimeService::decodeValues($product->values);
            $after = $before;
            $after[AbstractType::ASSOCIATION_VALUES_KEY][$association] = array_values(array_intersect($wanted, $existing));

            $changes = $this->diff($before, $after);

            if ($changes !== []) {
                $product->values = $after;
                $product->save();
            }

            return ['product' => $product, 'changes' => $changes, 'skipped' => array_values(array_diff($wanted, $existing))];
        });

        if ($outcome['skipped'] !== []) {
            $result['warnings'][] = "associations.{$association}: left out ".implode(', ', $outcome['skipped']).' because '.(count($outcome['skipped']) === 1 ? 'it was' : 'they were').' not created in this batch.';
        }

        if ($outcome['changes'] === []) {
            return $result;
        }

        $result['changes'] += $outcome['changes'];
        $result['action'] = $result['action'] === 'unchanged' ? 'updated' : $result['action'];

        $product = $outcome['product']->refresh();
        $touched[$product->id] = [
            'product' => $product,
            'action'  => $touched[$product->id]['action'] ?? 'updated',
            'changed' => [...($touched[$product->id]['changed'] ?? []), ...array_keys($outcome['changes'])],
        ];

        return $result;
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
            $attribute->value_per_channel && $attribute->value_per_locale   => AbstractType::CHANNEL_LOCALE_VALUES_KEY,
            (bool) $attribute->value_per_channel                            => AbstractType::CHANNEL_VALUES_KEY,
            (bool) $attribute->value_per_locale                             => AbstractType::LOCALE_VALUES_KEY,
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

        if (array_key_exists(AbstractType::ASSOCIATION_VALUES_KEY, $values)) {
            $values[AbstractType::ASSOCIATION_VALUES_KEY] = $this->normaliseAssociations($sku, $values[AbstractType::ASSOCIATION_VALUES_KEY]);
        }

        return $values;
    }

    /**
     * The lists that reach the shop: cross_sells (the other colours) and
     * andere_vormen (the other shapes). A sent list replaces the current one;
     * the product itself and duplicates are dropped (andere_vormen gets the
     * product back on saving, see AndereVormenMirror).
     *
     * @return array{cross_sells?: list<string>, andere_vormen?: list<string>}
     */
    private function normaliseAssociations(string $sku, mixed $associations): array
    {
        $writable = [AbstractType::CROSS_SELLS_ASSOCIATION_KEY, AbstractType::ANDERE_VORMEN_ASSOCIATION_KEY];

        if (! is_array($associations) || array_diff(array_keys($associations), $writable)) {
            throw new ProductUpsertException('values.associations only takes "cross_sells" and "andere_vormen" (up_sells and related_products are not shown on the site).');
        }

        $normalised = [];

        foreach ($writable as $key) {
            if (! array_key_exists($key, $associations)) {
                continue;
            }

            $list = $associations[$key] ?? [];

            if (! is_array($list) || ! array_is_list($list) || array_filter($list, fn (mixed $value): bool => ! is_string($value))) {
                throw new ProductUpsertException("values.associations.{$key} must be a list of SKUs.");
            }

            $list = array_filter(array_map('trim', $list), fn (string $value): bool => $value !== '' && $value !== $sku);

            $normalised[$key] = array_values(array_unique($list));
        }

        return $normalised;
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
            AbstractType::ASSOCIATION_VALUES_KEY    => 1,
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
}
