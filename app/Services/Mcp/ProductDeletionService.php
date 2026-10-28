<?php

namespace App\Services\Mcp;

use App\Exceptions\ProductUpsertException;
use App\Mcp\AdminPermission;
use App\Observers\AndereVormenMirror;
use App\Services\DeliveryTimeService;
use App\Services\ProductService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Product\Models\Product;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Type\AbstractType;
use Webkul\User\Models\Admin;

/**
 * Deletes products for the MCP server, the way the admin's delete does: the
 * delete events remove the product from WooCommerce (a parent goes to the
 * trash with its variations, a variation is deleted for good) and from the
 * search index.
 *
 * A parent is only deleted together with its variants when that is asked for
 * explicitly. Other products that list a deleted SKU as a cross-sell or as
 * another shape lose that reference, and the parent of a deleted variant is re-synced so the shop's
 * size list follows.
 */
class ProductDeletionService
{
    public const MAX_ITEMS = 50;

    /** Association lists a deleted SKU is taken out of. */
    private const CLEANED_ASSOCIATIONS = [
        AbstractType::CROSS_SELLS_ASSOCIATION_KEY,
        AbstractType::ANDERE_VORMEN_ASSOCIATION_KEY,
    ];

    public function __construct(
        private ProductRepository $productRepository,
        private ProductService $productService,
        private ProductSyncDispatcher $syncDispatcher,
    ) {}

    /**
     * @param  list<string>  $skus
     * @return array{dry_run: bool, summary: array<string, int>, results: list<array<string, mixed>>, cross_sells_cleaned: list<string>}
     */
    public function delete(array $skus, Admin $admin, bool $deleteVariants, bool $dryRun = false): array
    {
        if (! AdminPermission::allows($admin, 'catalog.products.delete')) {
            throw new ProductUpsertException('Your admin role lacks the catalog.products.delete permission.');
        }

        $skus = array_values(array_unique(array_filter(array_map('trim', $skus))));

        if ($skus === [] || count($skus) > self::MAX_ITEMS) {
            throw new ProductUpsertException('Send between 1 and '.self::MAX_ITEMS.' SKUs.');
        }

        $products = Product::query()->with(['parent:id,sku', 'variants:id,sku,parent_id'])->whereIn('sku', $skus)->get()->keyBy('sku');
        $results = [];
        $deletable = [];

        foreach ($skus as $sku) {
            $product = $products->get($sku);

            if (! $product) {
                $results[] = ['sku' => $sku, 'action' => 'error', 'errors' => ["{$sku} does not exist."]];

                continue;
            }

            $variants = $product->variants->pluck('sku')->all();

            if ($variants !== [] && ! $deleteVariants) {
                $results[] = ['sku' => $sku, 'action' => 'error', 'errors' => ["{$sku} has ".count($variants).' variants; pass delete_variants: true to delete it with all of them.']];

                continue;
            }

            if ($product->parent && isset($deletable[$product->parent->sku])) {
                $results[] = ['sku' => $sku, 'action' => $dryRun ? 'would_delete' : 'deleted', 'note' => "Deleted with its parent {$product->parent->sku}."];

                continue;
            }

            $deletable[$sku] = $product;

            $result = array_filter([
                'sku'             => $sku,
                'action'          => $dryRun ? 'would_delete' : 'deleted',
                'type'            => $product->type,
                'parent'          => $product->parent?->sku,
                'variants'        => $variants ?: null,
                'woocommerce'     => $product->parent_id ? 'variation deleted' : 'moved to the trash (with its variations)',
                'cross_sold_by'   => $this->crossSoldBy([$sku, ...$variants]) ?: null,
            ]);

            if ($product->bol_com_sync || $product->variants()->where('bol_com_sync', true)->exists()) {
                $result['warnings'][] = 'Synced to Bol.com: the Bol offer is not removed; end it in Bol.';
            }

            $results[] = $result;
        }

        $cleaned = [];

        if (! $dryRun && $deletable !== []) {
            $cleaned = $this->deleteProducts($deletable, $admin);
        }

        return [
            'dry_run'             => $dryRun,
            'summary'             => array_count_values(array_column($results, 'action')) + ['total' => count($results)],
            'results'             => $results,
            'cross_sells_cleaned' => $cleaned,
        ];
    }

    /**
     * @param  array<string, Product>  $products
     * @return list<string> The products whose cross-sells were cleaned.
     */
    private function deleteProducts(array $products, Admin $admin): array
    {
        $deletedSkus = [];
        $parentIds = [];

        foreach ($products as $sku => $product) {
            $variantSkus = $product->variants->pluck('sku')->all();

            Event::dispatch('catalog.product.delete.before', $product->id);

            $this->productRepository->delete($product->id);

            Event::dispatch('catalog.product.delete.after', $product->id);

            Log::channel('mcp')->info('product deleted', ['admin' => $admin->email, 'sku' => $sku, 'variants' => $variantSkus]);

            array_push($deletedSkus, $sku, ...$variantSkus);

            if ($product->parent_id) {
                $parentIds[$product->parent_id] = true;
            }
        }

        $touched = $this->removeFromCrossSells($deletedSkus);

        $parents = Product::query()->whereIn('id', array_keys($parentIds))->get();

        foreach ($parents as $parent) {
            try {
                $this->productService->triggerWCSyncForParent($parent);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->syncDispatcher->dispatch($touched);

        return array_values(array_map(fn (array $entry): string => $entry['product']->sku, $touched));
    }

    /**
     * @param  list<string>  $skus
     * @return array<int, array{product: Product, action: string, changed: list<string>}>
     */
    private function removeFromCrossSells(array $skus): array
    {
        $touched = [];

        // Each list is cleaned on every product that has it, so the mirror
        // has nothing to add.
        AndereVormenMirror::paused(function () use ($skus, &$touched): void {
            foreach ($this->crossSellingProducts($skus) as $product) {
                $values = DeliveryTimeService::decodeValues($product->values);
                $changed = [];

                foreach (self::CLEANED_ASSOCIATIONS as $association) {
                    $current = (array) ($values[AbstractType::ASSOCIATION_VALUES_KEY][$association] ?? []);
                    $left = array_values(array_diff($current, $skus));

                    if ($left !== $current) {
                        // A group of one is no group.
                        $values[AbstractType::ASSOCIATION_VALUES_KEY][$association] = $association === AbstractType::ANDERE_VORMEN_ASSOCIATION_KEY && count($left) < 2 ? [] : $left;
                        $changed[] = "associations.{$association}";
                    }
                }

                $product->values = $values;
                $product->save();

                $touched[$product->id] = ['product' => $product, 'action' => 'updated', 'changed' => $changed];
            }
        });

        return $touched;
    }

    /**
     * @param  list<string>  $skus
     * @return list<string>
     */
    private function crossSoldBy(array $skus): array
    {
        return $this->crossSellingProducts($skus)->pluck('sku')->all();
    }

    /**
     * Parent and standalone products that list one of the SKUs as cross-sell
     * or as another shape.
     *
     * @param  list<string>  $skus
     * @return Collection<int, Product>
     */
    private function crossSellingProducts(array $skus)
    {
        return Product::query()
            ->whereNull('parent_id')
            ->whereNotIn('sku', $skus)
            ->where(function ($query) use ($skus): void {
                foreach ($skus as $sku) {
                    foreach (self::CLEANED_ASSOCIATIONS as $association) {
                        $query->orWhereRaw("JSON_CONTAINS(JSON_EXTRACT(`values`, '$.associations.{$association}'), ?)", [json_encode($sku)]);
                    }
                }
            })
            ->orderBy('sku')
            ->get();
    }
}
