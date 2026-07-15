<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Attribute\Models\Attribute;

/**
 * Core logic for the generic bulk product editor.
 *
 * Both the live preview and the queued apply job go through this service so the
 * set of affected products and the resulting value are always identical.
 *
 * All supported attributes are non-localized, non-channel text/textarea fields,
 * so their values live at `values.common.<code>`.
 *
 * @phpstan-type Filters array{brand?:string, sku_prefix?:string, scope?:string, condition_attribute?:string, condition_operator?:string, condition_value?:string}
 * @phpstan-type Operation array{target:string, type:string, find?:string, replace?:string, value?:string}
 */
class BulkEditService
{
    /**
     * Attribute codes that may be filtered on or edited. Cached per request.
     *
     * @var list<string>|null
     */
    private ?array $editableCodes = null;

    /**
     * Non-localized, non-channel text/textarea attribute codes, ordered.
     *
     * @return list<string>
     */
    public function editableAttributeCodes(): array
    {
        if ($this->editableCodes === null) {
            $this->editableCodes = Attribute::query()
                ->whereIn('type', ['text', 'textarea'])
                ->where('value_per_locale', 0)
                ->where('value_per_channel', 0)
                ->orderBy('code')
                ->pluck('code')
                ->all();
        }

        return $this->editableCodes;
    }

    /**
     * Distinct brand (merk) values present on products.
     *
     * @return list<string>
     */
    public function brands(): array
    {
        return Product::query()
            ->whereRaw("`values`->>'$.common.merk' IS NOT NULL")
            ->whereRaw("`values`->>'$.common.merk' != 'null'")
            ->selectRaw("DISTINCT `values`->>'$.common.merk' as brand")
            ->orderBy('brand')
            ->pluck('brand')
            ->all();
    }

    /**
     * Products that both match the filters and would actually change under the
     * given operation. This is the single source of truth for preview + apply.
     *
     * @param  Filters  $filters
     * @param  Operation  $operation
     */
    public function affectedQuery(array $filters, array $operation): Builder
    {
        $target = $this->assertValidCode($operation['target']);
        $path = $this->jsonPath($target);

        $query = $this->baseQuery($filters, $target);

        switch ($operation['type']) {
            case 'replace':
                $find = (string) ($operation['find'] ?? '');
                if ($find === '') {
                    throw new InvalidArgumentException('Zoektekst mag niet leeg zijn.');
                }
                $query->whereRaw("{$path} LIKE ?", ['%'.$this->escapeLike($find).'%']);
                break;

            case 'set':
                $value = (string) ($operation['value'] ?? '');
                $query->whereRaw("NOT ({$path} <=> ?)", [$value]);
                break;

            case 'clear':
                $query->whereRaw("({$path} IS NOT NULL AND {$path} != '' AND {$path} != 'null')");
                break;

            default:
                throw new InvalidArgumentException("Onbekende bewerking: {$operation['type']}");
        }

        return $query;
    }

    /**
     * Read-only preview: how many products change and 20 random before/after rows.
     *
     * @param  Filters  $filters
     * @param  Operation  $operation
     * @return array{count:int, samples:list<array{sku:string, before:string, after:string}>}
     */
    public function preview(array $filters, array $operation): array
    {
        $query = $this->affectedQuery($filters, $operation);

        $count = (clone $query)->count();

        $samples = (clone $query)
            ->inRandomOrder()
            ->limit(20)
            ->get(['id', 'sku', 'values'])
            ->map(function (Product $product) use ($operation): array {
                $before = $this->readValue($product, $operation['target']);

                return [
                    'sku'    => (string) $product->sku,
                    'before' => $before,
                    'after'  => $this->applyOperation($before, $operation),
                ];
            })
            ->all();

        return ['count' => $count, 'samples' => $samples];
    }

    /**
     * Compute the new attribute value for a single product's current value.
     *
     * @param  Operation  $operation
     */
    public function applyOperation(string $current, array $operation): string
    {
        return match ($operation['type']) {
            'replace' => str_replace((string) $operation['find'], (string) ($operation['replace'] ?? ''), $current),
            'set'     => (string) ($operation['value'] ?? ''),
            'clear'   => '',
            default   => $current,
        };
    }

    /**
     * The current stored value for the operation's target attribute, guarding
     * against double-encoded `values` columns.
     */
    public function readValue(Product $product, string $code): string
    {
        $values = $this->values($product);

        return (string) ($values['common'][$code] ?? '');
    }

    /**
     * Decode the product's `values`, tolerating the double-encoded-JSON columns
     * that exist for a subset of products in this dataset.
     *
     * @return array<string, mixed>
     */
    public function values(Product $product): array
    {
        $values = $product->values;

        if (is_string($values)) {
            $values = json_decode($values, true);
        }

        return is_array($values) ? $values : [];
    }

    /**
     * Guard every attribute code that is interpolated into a raw JSON path
     * against the editable-attribute whitelist to prevent SQL injection.
     */
    public function assertValidCode(string $code): string
    {
        if (! in_array($code, $this->editableAttributeCodes(), true)) {
            throw new InvalidArgumentException("Onbekend of niet-bewerkbaar attribuut: {$code}");
        }

        return $code;
    }

    /**
     * Base query: products carrying the target attribute, narrowed by filters.
     *
     * @param  Filters  $filters
     */
    private function baseQuery(array $filters, string $target): Builder
    {
        $query = Product::query()
            ->whereRaw("{$this->jsonPath($target)} IS NOT NULL");

        if (! empty($filters['brand'])) {
            $brand = (string) $filters['brand'];
            $query->where(function (Builder $sub) use ($brand) {
                $sub->where('values->common->merk', $brand)
                    ->orWhereExists(function ($exists) use ($brand) {
                        $exists->select(DB::raw(1))
                            ->from('products as brand_parent')
                            ->whereColumn('products.parent_id', 'brand_parent.id')
                            ->where('brand_parent.values->common->merk', $brand);
                    });
            });
        }

        if (! empty($filters['sku_prefix'])) {
            $query->where('sku', 'like', $this->escapeLike((string) $filters['sku_prefix']).'%');
        }

        if (($filters['scope'] ?? null) === 'parents') {
            $query->whereNull('parent_id');
        } elseif (($filters['scope'] ?? null) === 'variants') {
            $query->whereNotNull('parent_id');
        }

        if (! empty($filters['condition_attribute']) && ! empty($filters['condition_operator'])) {
            $this->applyCondition(
                $query,
                $this->assertValidCode((string) $filters['condition_attribute']),
                (string) $filters['condition_operator'],
                (string) ($filters['condition_value'] ?? ''),
            );
        }

        return $query;
    }

    private function applyCondition(Builder $query, string $code, string $operator, string $value): void
    {
        $path = $this->jsonPath($code);

        match ($operator) {
            'contains' => $query->whereRaw("{$path} LIKE ?", ['%'.$this->escapeLike($value).'%']),
            'equals'   => $query->whereRaw("{$path} = ?", [$value]),
            'empty'    => $query->whereRaw("({$path} IS NULL OR {$path} = '' OR {$path} = 'null')"),
            default    => throw new InvalidArgumentException("Onbekende voorwaarde: {$operator}"),
        };
    }

    private function jsonPath(string $code): string
    {
        return "`values`->>'$.common.{$code}'";
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
