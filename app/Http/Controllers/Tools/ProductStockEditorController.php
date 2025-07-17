<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Product\Repositories\ProductRepository;

class ProductStockEditorController extends Controller
{
    public function index(ProductRepository $productRepository)
    {
        $data = [];

        $data['products'] = $productRepository->select([
            'id',
            DB::raw("COALESCE(JSON_UNQUOTE(`values`->'$.common.productnaam'), '') as productnaam"),
            DB::raw("COALESCE(JSON_UNQUOTE(`values`->'$.common.maat'), '') as maat"),
            DB::raw("COALESCE(JSON_UNQUOTE(`values`->'$.common.voorraad_eurogros'), '') as voorraad_eurogros"),
            DB::raw("COALESCE(JSON_UNQUOTE(`values`->'$.common.voorraad_5_korting_handmatig'), '') as voorraad_5_korting_handmatig"),
            DB::raw("COALESCE(JSON_UNQUOTE(`values`->'$.common.voorraad_hw_5_korting'), '') as voorraad_hw_5_korting"),
            DB::raw("COALESCE(JSON_UNQUOTE(`values`->'$.common.uitverkoop_15_korting'), '') as uitverkoop_15_korting"),
        ])->whereNotNull('parent_id')
            ->where('values->common->onderkleed', 'Zonder onderkleed')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('products as parent')
                    ->whereColumn('products.parent_id', 'parent.id')
                    ->where('parent.values->common->merk', request()->input('brand'));
            })
            ->where('values->common->maat', '!=', 'Maatwerk')
            ->where('values->common->maat', '!=', 'Rond Maatwerk')
            ->paginate(perPage: 10)
            ->through(function ($product) {
                if ($product->voorraad_eurogros === 'null' || $product->voorraad_eurogros === '0') {
                    $product->voorraad_eurogros = '';
                }

                if ($product->voorraad_5_korting_handmatig === 'null' || $product->voorraad_5_korting_handmatig === '0') {
                    $product->voorraad_5_korting_handmatig = '';
                }
                if ($product->voorraad_hw_5_korting === 'null' || $product->voorraad_hw_5_korting === '0') {
                    $product->voorraad_hw_5_korting = '';
                }
                if ($product->uitverkoop_15_korting === 'null' || $product->uitverkoop_15_korting === '0') {
                    $product->uitverkoop_15_korting = '';
                }

                return $product;
            });

        $merken = DB::select("SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(`values`, '$.common.merk')) AS brand FROM products WHERE `values`->'$.common.merk' IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(`values`, '$.common.merk')) != 'null'");
        $data['brands'] = ['--- KIES EEN MERK ---'];
        $data['brands'] = array_merge($data['brands'], array_column($merken, 'brand'));
        $data['current_brand'] = request()->input('brand');

        return view('admin::tools.product-stock-editor', $data);
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
            $values['common']['voorraad_eurogros'] = (int) $data['voorraad_eurogros'];
            $values['common']['voorraad_5_korting_handmatig'] = (int) $data['voorraad_5_korting_handmatig'];
            $values['common']['voorraad_hw_5_korting'] = (int) $data['voorraad_hw_5_korting'];
            $values['common']['uitverkoop_15_korting'] = (int) $data['uitverkoop_15_korting'];
            $product->values = $values;
            $product->save();

            Event::dispatch('catalog.product.update.after', $product);
        }

        $parents = $productRepository->findWhereIn('id', $parents);
        foreach ($parents as $parent) {
            Event::dispatch('catalog.product.update.after', $parent);
        }

        if ( $request->has('next_page') ){
            session()->flash('info', 'Producten bijgewerkt. Ga verder met de volgende producten.');
            return response()->redirectToRoute('admin.tools.product-stock-editor.index', ['page' => $request->input('next_page'), 'brand' => $request->input('brand')]);
        } else {
            session()->flash('success', 'Producten bijgewerkt. Je hebt alle producten gehad.');
            return response()->redirectToRoute('admin.tools.product-stock-editor.index', ['page' => 1]);
        }

    }
}
