<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExcludeCompetitorPriceRequest;
use App\Models\CompetitorPrice;
use App\Services\CompetitorPriceAnalysis;
use App\Services\CompetitorPriceExclusionService;
use App\Services\DeliveryTimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Webkul\Product\Models\Product;

/**
 * The competitor analysis panel on the product edit screen, and taking a
 * competitor price out of (or back into) the analysis. Both changes recompute
 * the size's selling price at once and sync it, like the nightly run would.
 */
class CompetitorAnalysisController extends Controller
{
    public function __construct(
        private CompetitorPriceAnalysis $analysis,
        private CompetitorPriceExclusionService $exclusions,
    ) {}

    public function show(int $productId): JsonResponse
    {
        abort_unless(bouncer()->hasPermission('catalog.products.edit'), 401, 'This action is unauthorized.');

        return $this->panel(Product::query()->findOrFail($productId));
    }

    public function exclude(ExcludeCompetitorPriceRequest $request, int $productId, CompetitorPrice $competitorPrice): JsonResponse
    {
        $product = $this->productFor($productId, $competitorPrice);

        $this->exclusions->exclude(collect([$competitorPrice]), $request->validated('reason'), auth('admin')->id());

        return $this->panel($product, "{$competitorPrice->shop} telt niet meer mee voor {$competitorPrice->sku}.");
    }

    public function include(int $productId, CompetitorPrice $competitorPrice): JsonResponse
    {
        abort_unless(bouncer()->hasPermission('catalog.products.edit'), 401, 'This action is unauthorized.');

        $product = $this->productFor($productId, $competitorPrice);

        $this->exclusions->include(collect([$competitorPrice]));

        return $this->panel($product, "{$competitorPrice->shop} telt weer mee voor {$competitorPrice->sku}.");
    }

    /**
     * The edited product, which must be the rug of the competitor price (its
     * parent) or the size itself.
     */
    private function productFor(int $productId, CompetitorPrice $competitorPrice): Product
    {
        $product = Product::query()->findOrFail($productId);

        $belongs = $product->sku === $competitorPrice->sku
            || $product->variants()->where('sku', $competitorPrice->sku)->exists();

        abort_unless($belongs, 404);

        return $product;
    }

    /**
     * The panel HTML plus the current selling price of every analysed size,
     * so the page can update price fields that the recompute just changed.
     */
    private function panel(Product $product, ?string $message = null): JsonResponse
    {
        $variants = $this->variantsOf($product);
        $sizes = $this->analysis->forVariants($variants);

        return response()->json([
            'html'        => view('admin::catalog.products.edit.competitor-analysis.panel', [
                'product' => $product,
                'sizes'   => $sizes,
                'message' => $message,
            ])->render(),
            'prices'      => $variants->mapWithKeys(fn (Product $variant): array => [$variant->sku => self::sellingPrice($variant)])->all(),
            'competition' => (object) collect($sizes)->mapWithKeys(fn (array $size): array => [$size['sku'] => [
                'competitors' => $size['competitors'],
                'cheapest'    => $size['cheapest']['price'] ?? null,
                'shop'        => $size['cheapest']['shop'] ?? null,
            ]])->all(),
        ]);
    }

    /**
     * The selling price as stored, including derived "Met onderkleed" prices
     * the recompute may also have changed.
     */
    private static function sellingPrice(Product $variant): ?float
    {
        $price = DeliveryTimeService::decodeValues($variant->values)['common']['prijs'] ?? null;
        $amount = is_array($price) ? ($price['EUR'] ?? null) : $price;

        return is_numeric($amount) ? (float) $amount : null;
    }

    /**
     * Fresh from the database: the recompute has just changed their prices.
     *
     * @return Collection<int, Product>
     */
    private function variantsOf(Product $product): Collection
    {
        return $product->parent_id === null ? $product->variants()->get() : collect([$product->fresh()]);
    }
}
