<?php

namespace App\Http\Controllers;

use App\Services\ProductService;
use Illuminate\Http\Request;
use Webkul\Product\Repositories\ProductRepository;

class ProductHelperController extends Controller
{
    public function __construct(private readonly ProductService $productService)
    {
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
}
