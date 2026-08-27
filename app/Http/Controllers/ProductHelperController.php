<?php

namespace App\Http\Controllers;

use App\Services\ProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Webkul\Product\Models\Product;
use Webkul\WooCommerce\Services\WooCommerceService;

class ProductHelperController extends Controller
{
    public function __construct(private readonly ProductService $productService) {}

    public function redirectToFrontend(Product $product)
    {
        $connector = app(WooCommerceService::class);

        if ($product->parent !== null) {
            $product = $product->parent;
        }

        $products = $connector->requestApiAction(
            'getProductWithSku',
            [],
            ['sku' => $product->sku]
        );

        if (isset($products[0])) {
            return redirect($products[0]['permalink']);
        }

        abort(418, 'External product not found.');
    }

    /**
     * Open the admin edit screen of the product with the given SKU.
     */
    public function redirectToEditBySku(string $sku): RedirectResponse
    {
        $product = Product::query()->where('sku', $sku)->firstOrFail();

        return redirect()->route('admin.catalog.products.edit', ['id' => $product->id]);
    }

    public function metaFields(Request $request)
    {
        $sku = $request->input('sku');

        $product = Product::where('sku', $sku)->first();
        $naam = $request->input('title', $product->values['common']['productnaam']);
        $merk = $request->input('merk', $product->values['common']['merk']);

        $metaTitle = $this->productService->generateMetaTitle($naam, $merk);
        $metaDescription = $this->productService->generateMetaDescription($naam);

        return response()->json(['meta_title' => $metaTitle, 'meta_description' => $metaDescription]);
    }

    /**
     * De afgeleide prijs en adviesverkoopprijs van een met-onderkleed-variant.
     *
     * De adviesverkoopprijs is null wanneer de kale variant er zelf geen heeft;
     * de knop laat dat veld dan ongemoeid.
     */
    public function price(Request $request)
    {
        $sku = $request->input('sku');

        $product = Product::where('sku', $sku)->first();

        $withoutOnderkleed = $this->productService->getUnderrugAlternative($product);
        $base = $withoutOnderkleed !== null ? ($withoutOnderkleed->values['common'] ?? []) : [];

        return response()->json([
            'price'                 => $this->productService->calculateMetOnderkleedPrice($product),
            'original_price'        => $base['prijs']['EUR'] ?? null,
            'advies_price'          => $this->productService->calculateMetOnderkleedAdviesPrice($product),
            'original_advies_price' => $base['adviesverkoopprijs']['EUR'] ?? null,
        ]);
    }

    public function sku(Request $request)
    {
        $data = $request->validate([
            'brand' => 'required|string|in:ERG,DES,KP,DMC',
        ]);
        $brand = $data['brand'];

        // SELECT  FROM products WHERE sku LIKE 'ERG%' AND sku NOT LIKE '%.%'

        $value = Product::where('sku', 'like', $brand.'%')->where('type', 'configurable')->rawValue("MAX(CAST(REPLACE(sku, '$brand', '') as UNSIGNED))");
        $value++;

        // Ensure the SKU doesn't exist already
        while (Product::where('sku', $brand.$value)->exists()) {
            $value++;
        }

        return response()->json(['sku' => $brand.$value]);
    }
}
