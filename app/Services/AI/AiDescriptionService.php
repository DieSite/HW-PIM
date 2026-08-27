<?php

namespace App\Services\AI;

use App\Models\AiDescriptionDraft;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Webkul\Attribute\Models\Attribute;

/**
 * Selecting which rugs to rewrite, and publishing the approved results.
 *
 * Only configurable parents are eligible: variants carry no descriptive values
 * and the shop reads the description from the parent.
 *
 * @phpstan-type Filters array{brand?:string, collection?:string, scope?:string, skus?:string}
 */
class AiDescriptionService
{
    public function __construct(
        private readonly ProductService $productService,
        private readonly DescriptionValidator $validator,
    ) {}

    /**
     * Parents matching the filters, in a stable order so a run is reproducible.
     *
     * @param  Filters  $filters
     * @return Builder<Product>
     */
    public function matchingQuery(array $filters): Builder
    {
        $query = Product::query()
            ->where('type', 'configurable')
            ->whereNull('parent_id');

        if (! empty($filters['brand'])) {
            $query->where('values->common->merk', (string) $filters['brand']);
        }

        if (! empty($filters['collection'])) {
            $query->where('values->common->collectie', (string) $filters['collection']);
        }

        if ($skus = $this->skuList($filters)) {
            $query->whereIn('sku', $skus);
        }

        match ($filters['scope'] ?? 'all') {
            'duplicates' => $this->onlyDuplicates($query),
            'empty'      => $query->whereRaw("(`values`->>'$.common.beschrijving_l' IS NULL OR `values`->>'$.common.beschrijving_l' = '')"),
            default      => null,
        };

        return $query->orderBy('id');
    }

    /**
     * Distinct collections present on parents, for the filter dropdown.
     *
     * @return list<string>
     */
    public function collections(): array
    {
        return Product::query()
            ->where('type', 'configurable')
            ->whereRaw("`values`->>'$.common.collectie' IS NOT NULL")
            ->whereRaw("`values`->>'$.common.collectie' != 'null'")
            ->selectRaw("DISTINCT `values`->>'$.common.collectie' as collection")
            ->orderBy('collection')
            ->pluck('collection')
            ->all();
    }

    /**
     * @return list<string>
     */
    public function brands(): array
    {
        return Product::query()
            ->where('type', 'configurable')
            ->whereRaw("`values`->>'$.common.merk' IS NOT NULL")
            ->whereRaw("`values`->>'$.common.merk' != 'null'")
            ->selectRaw("DISTINCT `values`->>'$.common.merk' as brand")
            ->orderBy('brand')
            ->pluck('brand')
            ->all();
    }

    /**
     * The values a draft is about to replace, so publishing can be undone.
     *
     * @param  list<string>  $fields
     * @return array<string, string>
     */
    public function snapshot(Product $product, array $fields): array
    {
        $values = $this->values($product);
        $previous = [];

        foreach ($fields as $code) {
            $previous[$code] = (string) ($values['common'][$code] ?? '');
        }

        return $previous;
    }

    /**
     * Write a draft's texts onto its product and push them to the shop.
     *
     * Uses Attribute::setProductValue so the value lands wherever the attribute
     * says it should, the same way ApplyPhotoroomTransformationJob writes back.
     */
    public function publish(AiDescriptionDraft $draft, bool $syncWoo = true): void
    {
        $product = Product::find($draft->product_id);

        if (! $product) {
            throw new RuntimeException("Product [{$draft->product_id}] bestaat niet meer.");
        }

        $texts = $draft->fields ?? [];

        if ($texts === []) {
            throw new RuntimeException("Concept [{$draft->id}] bevat geen teksten.");
        }

        $values = $this->values($product);
        $previous = [];

        foreach ($texts as $code => $text) {
            $attribute = Attribute::where('code', $code)->first();

            if (! $attribute) {
                continue;
            }

            $previous[$code] = (string) ($values['common'][$code] ?? '');
            $attribute->setProductValue($this->validator->normaliseHtml((string) $text), $values);
        }

        $product->values = $values;
        $product->save();

        Event::dispatch('catalog.product.update.after', $product);

        $draft->update([
            'status'          => AiDescriptionDraft::STATUS_APPLIED,
            'previous_values' => $previous,
            'applied_at'      => now(),
        ]);

        if ($syncWoo) {
            $this->productService->triggerWCSyncForParent($product);
        }
    }

    /**
     * Put back the values a published draft overwrote.
     */
    public function revert(AiDescriptionDraft $draft, bool $syncWoo = true): void
    {
        if (! $draft->isRevertible()) {
            throw new RuntimeException("Concept [{$draft->id}] is niet terug te draaien.");
        }

        $product = Product::find($draft->product_id);

        if (! $product) {
            throw new RuntimeException("Product [{$draft->product_id}] bestaat niet meer.");
        }

        $values = $this->values($product);

        foreach ($draft->previous_values ?? [] as $code => $text) {
            $attribute = Attribute::where('code', $code)->first();

            if ($attribute) {
                $attribute->setProductValue((string) $text, $values);
            }
        }

        $product->values = $values;
        $product->save();

        Event::dispatch('catalog.product.update.after', $product);

        $draft->update([
            'status'     => AiDescriptionDraft::STATUS_REJECTED,
            'applied_at' => null,
        ]);

        if ($syncWoo) {
            $this->productService->triggerWCSyncForParent($product);
        }
    }

    /**
     * Decode `values`, tolerating the double-encoded columns a subset of
     * products in this dataset still carry.
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
     * Narrow to products whose long description is shared with at least one
     * other product. This is the bulk of the catalogue: 3.458 parents carry
     * only 485 distinct texts between them.
     *
     * @param  Builder<Product>  $query
     */
    private function onlyDuplicates(Builder $query): void
    {
        $query->whereRaw(<<<'SQL'
            `values`->>'$.common.beschrijving_l' IN (
                SELECT * FROM (
                    SELECT d.`values`->>'$.common.beschrijving_l'
                    FROM products d
                    WHERE d.type = 'configurable'
                      AND d.`values`->>'$.common.beschrijving_l' IS NOT NULL
                    GROUP BY 1
                    HAVING COUNT(*) > 1
                ) AS duplicated
            )
            SQL);
    }

    /**
     * @param  Filters  $filters
     * @return list<string>
     */
    private function skuList(array $filters): array
    {
        $raw = trim((string) ($filters['skus'] ?? ''));

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), preg_split('/[\s,;]+/', $raw) ?: []),
            fn (string $sku): bool => $sku !== ''
        ));
    }
}
