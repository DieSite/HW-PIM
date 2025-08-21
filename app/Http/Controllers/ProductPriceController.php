<?php

namespace App\Http\Controllers;

use App\Services\ProductService;
use Illuminate\Http\Request;
use Webkul\Product\Repositories\ProductRepository;

class ProductPriceController extends Controller
{
    public function __construct(private readonly ProductService $productService)
    {
    }

    public function index(Request $request)
    {
        $sku = $request->input('sku');

        $product = app(ProductRepository::class)->findByField('sku', $sku)->first();

        $price = $this->productService->calculateMetOnderkleedPrice($product);
        $original = $this->productService->getUnderrugAlternative($product)->values['common']['prijs']['EUR'];

        return response()->json(['price' => $price, 'original_price' => $original]);
    }
}
