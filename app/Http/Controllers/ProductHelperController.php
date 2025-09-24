<?php

namespace App\Http\Controllers;

use App\Services\ProductService;
use Illuminate\Http\Request;
use Webkul\Product\Models\Product;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\WooCommerce\Services\WooCommerceService;

class ProductHelperController extends Controller
{
    public function __construct(private readonly ProductService $productService)
    {
    }

    public function redirectToFrontend(Product $product)
    {
        $connector = app(WooCommerceService::class);

        if ( $product->parent !== null ) {
            $product = $product->parent;
        }

        $products = $connector->requestApiAction(
            'getProductWithSku',
            [],
            ['sku' => $product->sku]
        );

        if ( isset($products[0]) ) {
            return redirect($products[0]['permalink']);
        }

        abort(418, 'External product not found.');
    }

    public function metaFields(Request $request)
    {
        $sku = $request->input('sku');

        $product = app(ProductRepository::class)->findByField('sku', $sku)->first();
        $naam = $request->input('title', $product->values['common']['productnaam']);
        $merk = $request->input('merk', $product->values['common']['merk']);

        $metaTitle = $this->productService->generateMetaTitle($naam, $merk);
        $metaDescription = $this->productService->generateMetaDescription($naam);

        return response()->json(['meta_title' => $metaTitle, 'meta_description' => $metaDescription]);
    }

    public function price(Request $request)
    {
        $sku = $request->input('sku');

        $product = app(ProductRepository::class)->findByField('sku', $sku)->first();

        $price = $this->productService->calculateMetOnderkleedPrice($product);
        $original = $this->productService->getUnderrugAlternative($product)->values['common']['prijs']['EUR'];

        return response()->json(['price' => $price, 'original_price' => $original]);
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
