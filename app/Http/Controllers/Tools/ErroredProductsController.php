<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class ErroredProductsController extends Controller
{
    public function index()
    {
        $products = Product::whereNotNull('additional')->select(
            'id',
            DB::raw("COALESCE(JSON_UNQUOTE(`values`->'$.common.productnaam'), '') as productnaam"),
            DB::raw("COALESCE(JSON_UNQUOTE(`values`->'$.common.maat'), '') as maat"),
            DB::raw("COALESCE(JSON_UNQUOTE(`values`->'$.common.voorraad_hw_5_korting'), '') as voorraad_hw_5_korting"),
        )->get();

        return view('admin::tools.errored-products', compact('products'));
    }
}
