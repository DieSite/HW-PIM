<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
}
