<?php

namespace App\Services\Mcp;

use App\Services\DeliveryTimeService;
use Illuminate\Database\Eloquent\Builder;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeFamily;
use Webkul\Core\Models\Channel;
use Webkul\Product\Models\Product;
use Webkul\Product\Type\AbstractType;

/**
 * Read side of the MCP server: product search, full product payloads and the
 * attribute catalogue an AI client needs to write valid values.
 */
class ProductCatalogReader
{
    public const MAX_PAGE_SIZE = 200;

    public const MAX_SKUS = 100;

    private const OPTIONS_PREVIEW = 100;

    /**
     * @param  array{skus?: list<string>, sku_prefix?: string, parent_sku?: string, family?: string, type?: string, attribute?: array{code: string, value: string}, limit?: int, page?: int}  $filters
     * @return array{total: int, page: int, limit: int, products: list<array<string, mixed>>}
     */
    public function search(array $filters): array
    {
        $limit = max(1, min(self::MAX_PAGE_SIZE, (int) ($filters['limit'] ?? 50)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $query = Product::query()
            ->with(['parent:id,sku', 'attribute_family:id,code'])
            ->withCount('variants')
            ->when($filters['skus'] ?? null, fn (Builder $query, array $skus) => $query->whereIn('sku', $skus))
            ->when($filters['sku_prefix'] ?? null, fn (Builder $query, string $prefix) => $query->where('sku', 'like', addcslashes($prefix, '%_\\').'%'))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['family'] ?? null, fn (Builder $query, string $family) => $query->whereHas('attribute_family', fn (Builder $query) => $query->where('code', $family)))
            ->when($filters['parent_sku'] ?? null, fn (Builder $query, string $parentSku) => $query->whereHas('parent', fn (Builder $query) => $query->where('sku', $parentSku)))
            ->when($filters['attribute'] ?? null, fn (Builder $query, array $attribute) => $query->where('values->common->'.$attribute['code'], $attribute['value']))
            ->orderBy('sku');

        $total = $query->count();

        $products = $query->forPage($page, $limit)->get()->map(function (Product $product): array {
            $common = DeliveryTimeService::decodeValues($product->values)[AbstractType::COMMON_VALUES_KEY] ?? [];

            return [
                'sku'            => $product->sku,
                'type'           => $product->type,
                'family'         => $product->attribute_family?->code,
                'parent_sku'     => $product->parent?->sku,
                'status'         => (bool) $product->status,
                'productnaam'    => $common['productnaam'] ?? null,
                'merk'           => $common['merk'] ?? null,
                'maat'           => $common['maat'] ?? null,
                'variants_count' => $product->variants_count,
                'updated_at'     => $product->updated_at?->toIso8601String(),
            ];
        })->all();

        return ['total' => $total, 'page' => $page, 'limit' => $limit, 'products' => $products];
    }

    /**
     * @param  list<string>  $skus
     * @return array{products: list<array<string, mixed>>, not_found: list<string>}
     */
    public function get(array $skus, bool $withVariants = true): array
    {
        $products = Product::query()
            ->with(['parent:id,sku', 'attribute_family:id,code', 'super_attributes:id,code', 'variants:id,sku,parent_id,status,values'])
            ->whereIn('sku', $skus)
            ->get();

        return [
            'products'  => $products->map(fn (Product $product): array => $this->present($product, $withVariants))->values()->all(),
            'not_found' => array_values(array_diff($skus, $products->pluck('sku')->all())),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Product $product, bool $withVariants): array
    {
        $axes = $product->super_attributes->pluck('code')->all();

        $data = [
            'sku'           => $product->sku,
            'type'          => $product->type,
            'family'        => $product->attribute_family?->code,
            'parent_sku'    => $product->parent?->sku,
            'status'        => (bool) $product->status,
            'bol_com_sync'  => (bool) $product->bol_com_sync,
            'sync_error'    => $product->additional['product_sync_error'] ?? null,
            'updated_at'    => $product->updated_at?->toIso8601String(),
            'values'        => DeliveryTimeService::decodeValues($product->values),
        ];

        if ($product->type === 'configurable') {
            $data['super_attributes'] = $axes;

            if ($withVariants) {
                $data['variants'] = $product->variants->map(function (Product $variant) use ($axes): array {
                    $common = DeliveryTimeService::decodeValues($variant->values)[AbstractType::COMMON_VALUES_KEY] ?? [];

                    return ['sku' => $variant->sku, 'status' => (bool) $variant->status] + array_intersect_key($common, array_flip($axes));
                })->values()->all();
            }
        }

        return $data;
    }

    /**
     * @return list<array{code: string, name: ?string, products: int}>
     */
    public function families(): array
    {
        return AttributeFamily::query()
            ->withCount('products')
            ->orderBy('code')
            ->get()
            ->map(fn (AttributeFamily $family): array => [
                'code'     => $family->code,
                'name'     => $family->name,
                'products' => $family->products_count,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function familyAttributes(string $familyCode, ?string $attributeCode = null): ?array
    {
        $family = AttributeFamily::query()->where('code', $familyCode)->first();

        if (! $family) {
            return null;
        }

        $attributes = $family->customAttributes()
            ->with('options.translations')
            ->when($attributeCode, fn ($query) => $query->where('attributes.code', $attributeCode))
            ->orderBy('attributes.code')
            ->get();

        return [
            'family'                => $family->code,
            'channels'              => Channel::query()->with('locales')->get()->mapWithKeys(fn (Channel $channel): array => [
                $channel->code => $channel->locales->pluck('code')->all(),
            ])->all(),
            'variant_axis_candidates' => $family->getConfigurableAttributes()->pluck('code')->all(),
            'attributes'            => $attributes->map(fn (Attribute $attribute): array => $this->presentAttribute($attribute, full: $attributeCode !== null))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAttribute(Attribute $attribute, bool $full): array
    {
        $data = [
            'code'     => $attribute->code,
            'name'     => $attribute->name,
            'type'     => $attribute->type,
            'section'  => match (true) {
                $attribute->value_per_channel && $attribute->value_per_locale => AbstractType::CHANNEL_LOCALE_VALUES_KEY,
                (bool) $attribute->value_per_channel                           => AbstractType::CHANNEL_VALUES_KEY,
                (bool) $attribute->value_per_locale                            => AbstractType::LOCALE_VALUES_KEY,
                default                                                         => AbstractType::COMMON_VALUES_KEY,
            },
            'required' => (bool) $attribute->is_required,
            'unique'   => (bool) $attribute->is_unique,
            'writable' => ! in_array($attribute->type, ProductUpsertService::UNSUPPORTED_ATTRIBUTE_TYPES, true),
        ];

        if ($attribute->validation) {
            $data['validation'] = $attribute->validation;
        }

        if ($attribute->type === 'price') {
            $data['format'] = 'object keyed by currency code, e.g. {"EUR": "329"}';
        }

        if ($attribute->type === 'textarea' && $attribute->enable_wysiwyg) {
            $data['format'] = 'HTML';
        }

        if (in_array($attribute->type, ['select', 'multiselect'], true)) {
            $options = $attribute->options->sortBy('sort_order');

            $data['options_count'] = $options->count();
            $data['options'] = $options
                ->when(! $full, fn ($options) => $options->take(self::OPTIONS_PREVIEW))
                ->map(fn ($option): array => array_filter(['code' => $option->code, 'label' => $option->label !== $option->code ? $option->label : null]))
                ->values()
                ->all();

            if (! $full && $data['options_count'] > self::OPTIONS_PREVIEW) {
                $data['options_truncated'] = true;
            }
        }

        return $data;
    }
}
