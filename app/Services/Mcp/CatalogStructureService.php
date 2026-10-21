<?php

namespace App\Services\Mcp;

use App\Exceptions\ProductUpsertException;
use App\Mcp\AdminPermission;
use App\Services\DeliveryTimeService;
use App\Services\WooCommerce\WooCommerceCategorySync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\Category\Models\Category;
use Webkul\Core\Models\Locale;
use Webkul\Product\Models\Product;
use Webkul\Product\Type\AbstractType;
use Webkul\User\Models\Admin;

/**
 * Categories and select options for the MCP server: list and create them.
 *
 * A new category is created in WooCommerce straight away (and mapped), so the
 * product export can send it. A new select option needs nothing in
 * WooCommerce: the shop creates the attribute term itself when the first
 * parent product using the option is synced.
 */
class CatalogStructureService
{
    public const MAX_ITEMS = 50;

    private const CATEGORY_CODE_PATTERN = '/^[\pL\pN][\pL\pN _&\-]*$/u';

    public function __construct(private WooCommerceCategorySync $categorySync) {}

    /**
     * @return list<array{code: string, name: string, parent: ?string, products: int, in_woocommerce: ?bool}>
     */
    public function categories(): array
    {
        $productCounts = [];

        Product::query()->whereNull('parent_id')->select(['id', 'values'])->chunkById(1000, function ($products) use (&$productCounts): void {
            foreach ($products as $product) {
                foreach ((array) (DeliveryTimeService::decodeValues($product->values)[AbstractType::CATEGORY_VALUES_KEY] ?? []) as $code) {
                    $productCounts[$code] = ($productCounts[$code] ?? 0) + 1;
                }
            }
        });

        $categories = Category::query()->orderBy('code')->get();
        $codesById = $categories->pluck('code', 'id');

        return $categories->map(fn (Category $category): array => [
            'code'           => $category->code,
            'name'           => $this->categorySync->name($category),
            'parent'         => $category->parent_id ? $codesById->get($category->parent_id) : null,
            'products'       => $productCounts[$category->code] ?? 0,
            'in_woocommerce' => $this->categorySync->isMapped($category->code),
        ])->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{dry_run: bool, results: list<array<string, mixed>>}
     */
    public function createCategories(array $items, Admin $admin, bool $dryRun = false): array
    {
        $this->authorize($admin, 'catalog.categories.create');
        $this->assertBatchSize($items);

        $results = [];
        $batchCodes = [];

        foreach ($items as $item) {
            $code = is_array($item) && is_string($item['code'] ?? null) ? trim($item['code']) : '';

            try {
                $results[] = $this->createCategory($code, $item, $batchCodes, $admin, $dryRun);
                $batchCodes[] = $code;
            } catch (ProductUpsertException $e) {
                $results[] = ['code' => $code, 'action' => 'error', 'errors' => [$e->getMessage()]];
            }
        }

        return ['dry_run' => $dryRun, 'results' => $results];
    }

    /**
     * Adds options to a select attribute. The code is what products store;
     * the label (default: the code) is shown in the admin. Without "after"
     * an option goes to the end of the list.
     *
     * @param  list<array<string, mixed>>  $options
     * @return array{dry_run: bool, attribute: string, results: list<array<string, mixed>>}
     */
    public function addOptions(string $attributeCode, array $options, Admin $admin, bool $dryRun = false): array
    {
        $this->authorize($admin, 'catalog.attributes.edit');
        $this->assertBatchSize($options);

        $attribute = Attribute::query()->where('code', $attributeCode)->first();

        if (! $attribute || ! in_array($attribute->type, ['select', 'multiselect'], true)) {
            throw new ProductUpsertException("{$attributeCode} is not a select attribute.");
        }

        $locales = DB::table('attribute_option_translations')
            ->join('attribute_options', 'attribute_options.id', '=', 'attribute_option_translations.attribute_option_id')
            ->where('attribute_options.attribute_id', $attribute->id)
            ->distinct()
            ->pluck('locale')
            ->merge(Locale::query()->where('status', 1)->pluck('code'))
            ->unique()
            ->values()
            ->all();
        $results = [];

        DB::beginTransaction();

        try {
            foreach ($options as $option) {
                $code = is_array($option) && is_string($option['code'] ?? null) ? trim($option['code']) : '';

                try {
                    $results[] = $this->addOption($attribute, $code, $option, $locales);
                } catch (ProductUpsertException $e) {
                    $results[] = ['code' => $code, 'action' => 'error', 'errors' => [$e->getMessage()]];
                }
            }
        } finally {
            $dryRun ? DB::rollBack() : DB::commit();
        }

        if (! $dryRun) {
            Log::channel('mcp')->info('attribute options added', ['admin' => $admin->email, 'attribute' => $attributeCode, 'options' => array_column(array_filter($results, fn (array $result): bool => $result['action'] === 'created'), 'code')]);
        }

        return ['dry_run' => $dryRun, 'attribute' => $attributeCode, 'results' => $results];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $batchCodes
     * @return array<string, mixed>
     */
    private function createCategory(string $code, array $item, array $batchCodes, Admin $admin, bool $dryRun): array
    {
        if (! preg_match(self::CATEGORY_CODE_PATTERN, $code) || mb_strlen($code) > 100) {
            throw new ProductUpsertException('A category code is a name of letters, digits, spaces, "&", "-" or "_", e.g. "Brinker".');
        }

        $existing = Category::query()->where('code', $code)->first();

        if ($existing) {
            return ['code' => $code, 'action' => 'exists', 'in_woocommerce' => $this->categorySync->isMapped($code)];
        }

        if (in_array(Str::lower($code), array_map(Str::lower(...), $batchCodes), true)) {
            throw new ProductUpsertException("{$code} is in this batch twice.");
        }

        $parentCode = isset($item['parent']) ? trim((string) $item['parent']) : '';
        $parent = $parentCode !== '' ? Category::query()->where('code', $parentCode)->first() : null;

        if ($parentCode !== '' && ! $parent && ! in_array($parentCode, $batchCodes, true)) {
            throw new ProductUpsertException("Parent category {$parentCode} does not exist.");
        }

        $name = trim((string) ($item['name'] ?? '')) ?: $code;

        if ($dryRun) {
            return ['code' => $code, 'action' => 'would_create', 'name' => $name, 'parent' => $parentCode ?: null];
        }

        $parentId = $parentCode !== '' ? Category::query()->where('code', $parentCode)->value('id') : null;
        $category = Category::query()->create(['code' => $code, 'parent_id' => $parentId]);

        if ($name !== $code) {
            $category->additional_data = ['locale_specific' => ['nl_NL' => ['name' => $name]]];
            $category->save();
        }

        Log::channel('mcp')->info('category created', ['admin' => $admin->email, 'code' => $code]);

        $result = ['code' => $code, 'action' => 'created', 'name' => $name, 'parent' => $parentCode ?: null];

        try {
            $result['woocommerce_id'] = $this->categorySync->ensure($category);
        } catch (Throwable $e) {
            report($e);

            $result['warnings'] = ["Created in the PIM, but WooCommerce failed: {$e->getMessage()}. It is retried when a product in this category is saved."];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $option
     * @param  list<string>  $locales
     * @return array<string, mixed>
     */
    private function addOption(Attribute $attribute, string $code, array $option, array $locales): array
    {
        if ($code === '' || mb_strlen($code) > 191) {
            throw new ProductUpsertException('Every option needs a "code" (at most 191 characters).');
        }

        $options = AttributeOption::query()->where('attribute_id', $attribute->id);

        if ((clone $options)->where('code', $code)->exists()) {
            return ['code' => $code, 'action' => 'exists'];
        }

        $after = isset($option['after']) ? trim((string) $option['after']) : null;

        if ($after !== null && $after !== '') {
            $anchor = (clone $options)->where('code', $after)->first();

            if (! $anchor) {
                throw new ProductUpsertException("\"after\": {$after} is not an option of {$attribute->code}.");
            }

            $sortOrder = (int) $anchor->sort_order + 1;
            (clone $options)->where('sort_order', '>=', $sortOrder)->increment('sort_order');
        } else {
            $sortOrder = (int) (clone $options)->max('sort_order') + 1;
        }

        $label = trim((string) ($option['label'] ?? '')) ?: $code;

        AttributeOption::query()->create(['attribute_id' => $attribute->id, 'code' => $code, 'sort_order' => $sortOrder]
            + array_fill_keys($locales, ['label' => $label]));

        return ['code' => $code, 'action' => 'created', 'label' => $label, 'sort_order' => $sortOrder];
    }

    /**
     * @param  array<mixed>  $items
     */
    private function assertBatchSize(array $items): void
    {
        if ($items === [] || count($items) > self::MAX_ITEMS) {
            throw new ProductUpsertException('Send between 1 and '.self::MAX_ITEMS.' items.');
        }
    }

    private function authorize(Admin $admin, string $permission): void
    {
        if (! AdminPermission::allows($admin, $permission)) {
            throw new ProductUpsertException("Your admin role lacks the {$permission} permission.");
        }
    }
}
