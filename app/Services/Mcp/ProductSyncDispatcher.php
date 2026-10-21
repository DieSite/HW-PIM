<?php

namespace App\Services\Mcp;

use App\Services\DeliveryTimeService;
use App\Services\ProductService;
use App\Services\WooCommerce\WooCommerceCategorySync;
use Illuminate\Support\Facades\Event;
use Throwable;
use Webkul\Category\Models\Category;
use Webkul\Product\Models\Product;
use Webkul\Product\Type\AbstractType;

/**
 * Starts the same downstream work as an admin save for products the MCP
 * changed, once per touched product/parent: the after-events (search index,
 * DAM, WooCommerce listener), the WooCommerce parent chain, the Bol sync for
 * Bol-enabled products, and the onderkleed stock copy when a stock field
 * changed. Categories the products are in that do not exist in WooCommerce
 * yet are created there first, or the export would leave them out.
 */
class ProductSyncDispatcher
{
    private const STOCK_ATTRIBUTES = [
        'voorraad_eurogros',
        'voorraad_5_korting_handmatig',
        'voorraad_hw_5_korting',
        'uitverkoop_15_korting',
    ];

    public function __construct(
        private ProductService $productService,
        private WooCommerceCategorySync $categorySync,
    ) {}

    /**
     * @param  array<int, array{product: Product, action: string, changed: list<string>}>  $touched
     */
    public function dispatch(array $touched): void
    {
        $this->ensureCategories(array_column($touched, 'product'));

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
     * @param  list<Product>  $products
     */
    private function ensureCategories(array $products): void
    {
        $codes = collect($products)
            ->flatMap(fn (Product $product): array => (array) (DeliveryTimeService::decodeValues($product->values)[AbstractType::CATEGORY_VALUES_KEY] ?? []))
            ->unique()
            ->reject(fn (string $code): bool => $this->categorySync->isMapped($code) !== false);

        Category::query()->whereIn('code', $codes->all())->each(function (Category $category): void {
            try {
                $this->categorySync->ensure($category);
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
