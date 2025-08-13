<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Product\Repositories\ProductRepository;

class ProductHWStockEditorController extends Controller
{
    public function index(ProductRepository $productRepository)
    {
        $data = [];

        $builder = $productRepository->select([
            'id',
            DB::raw("COALESCE(JSON_UNQUOTE(`values`->'$.common.productnaam'), '') as productnaam"),
            DB::raw("COALESCE(JSON_UNQUOTE(`values`->'$.common.maat'), '') as maat"),
            DB::raw("COALESCE(JSON_UNQUOTE(`values`->'$.common.voorraad_hw_5_korting'), '') as voorraad_hw_5_korting"),
        ])->whereNotNull('parent_id')
            ->where('values->common->onderkleed', 'Zonder onderkleed')
            ->where('values->common->maat', '!=', 'Maatwerk')
            ->where('values->common->maat', '!=', 'Rond Maatwerk')
            ->where('values->common->voorraad_hw_5_korting', '>', '0')
            ->where('values->common->voorraad_hw_5_korting', '!=', 'null');

        if (request()->has('search')) {
            $builder = $builder->where('values->common->productnaam', 'LIKE', '%'.request()->input('search').'%');
        }

        $data['products'] = $builder->paginate(perPage: 249)
            ->through(function ($product) {

                if ($product->voorraad_hw_5_korting === 'null' || $product->voorraad_hw_5_korting === '0') {
                    $product->voorraad_hw_5_korting = '';
                }

                return $product;
            });

        return view('admin::tools.product-hw-stock-editor', $data);
    }

    public function update(Request $request, ProductRepository $productRepository)
    {
        $productData = $request->input('product', []);
        $products = $productRepository->findWhereIn('id', array_keys($productData));
        $parents = [];
        foreach ($products as $product) {
            $data = $productData[$product->id];
            if (is_null($product)) {
                continue;
            }

            $parents[$product->parent_id] = $product->parent_id;

            $values = $product->values;

            if (((int)$values['common']['voorraad_hw_5_korting']) === ((int)$data['voorraad_hw_5_korting'])) {
                continue;
            }

            $values['common']['voorraad_hw_5_korting'] = (int) $data['voorraad_hw_5_korting'];
            $product->values = $values;
            $product->save();

            Event::dispatch('catalog.product.update.after', $product);
            app(ProductService::class)->copyStockValuesOnderkleed($product);
        }

        $parents = $productRepository->findWhereIn('id', $parents);
        foreach ($parents as $parent) {
            Event::dispatch('catalog.product.update.after', $parent);
        }

        if ($request->has('next_page')) {
            session()->flash('info', 'Producten bijgewerkt. Ga verder met de volgende producten.');
            $data = ['page' => $request->input('next_page')];
            if ($request->has('search')) {
                $data['search'] = $request->input('search');
            }

            return response()->redirectToRoute('admin.tools.product-hw-stock-editor.index', $data);
        } else {
            session()->flash('success', 'Producten bijgewerkt. Je hebt alle producten gehad.');

            return response()->redirectToRoute('admin.tools.product-hw-stock-editor.index', ['page' => 1]);
        }

    }
}
