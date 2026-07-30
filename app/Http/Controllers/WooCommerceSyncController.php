<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WooCommerceSyncController extends Controller
{
    public function __construct(private ProductService $productService) {}

    public function retry(Request $request, int $productId): RedirectResponse
    {
        /** @var Product $product */
        $product = Product::with(['parent', 'variants'])->findOrFail($productId);

        if (is_null($product->parent)) {
            $this->productService->triggerWCSyncForParent($product);
        } else {
            $this->productService->triggerWCSyncForChild($product);
        }

        return back()->with('success', 'Synchronisatie met WooCommerce opnieuw gestart. De voortgang verschijnt hieronder.');
    }

    /**
     * Renders the sync panel on its own, so the panel on the product edit page
     * can refresh itself while a sync is queued or running.
     */
    public function timeline(int $productId): View
    {
        /** @var Product $product */
        $product = Product::with('wooCommerceSyncEvents')->findOrFail($productId);

        return view('admin::custom.wooCommerce.timeline', [
            'product'  => $product,
            'events'   => $product->wooCommerceSyncEvents,
            'fragment' => true,
        ]);
    }
}
