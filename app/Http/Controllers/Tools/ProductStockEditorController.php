<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\CompetitorPricingService;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class ProductStockEditorController extends Controller
{
    public function index()
    {
        $data = [];

        $builder = Product::select([
            'id',
            DB::raw("COALESCE(JSON_UNQUOTE(`values`->'$.common.productnaam'), '') as productnaam"),
            DB::raw("COALESCE(JSON_UNQUOTE(`values`->'$.common.maat'), '') as maat"),
            DB::raw("COALESCE(JSON_UNQUOTE(`values`->'$.common.voorraad_eurogros'), '') as voorraad_eurogros"),
            DB::raw("COALESCE(JSON_UNQUOTE(`values`->'$.common.voorraad_5_korting_handmatig'), '') as voorraad_5_korting_handmatig"),
            DB::raw("COALESCE(JSON_UNQUOTE(`values`->'$.common.voorraad_hw_5_korting'), '') as voorraad_hw_5_korting"),
            DB::raw("COALESCE(JSON_UNQUOTE(`values`->'$.common.uitverkoop_15_korting'), '') as uitverkoop_15_korting"),
            DB::raw("COALESCE(JSON_UNQUOTE(`values`->'$.common.extra_korting'), '') as extra_korting"),
            DB::raw("COALESCE(JSON_UNQUOTE(`values`->'$.common.adviesverkoopprijs.EUR'), '') as adviesverkoopprijs"),
            DB::raw("COALESCE(JSON_UNQUOTE(`values`->'$.common.prijs.EUR'), '') as prijs"),
        ])->whereNotNull('parent_id')
            ->where('values->common->onderkleed', 'Zonder onderkleed')
            ->where('values->common->maat', '!=', 'Maatwerk')
            ->where('values->common->maat', '!=', 'Rond Maatwerk');

        if (request()->has('brand')) {
            $builder = $builder->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('products as parent')
                    ->whereColumn('products.parent_id', 'parent.id')
                    ->where('parent.values->common->merk', request()->input('brand'));
            });
        }

        if (request()->has('search')) {
            $builder = $builder->where('values->common->productnaam', 'LIKE', '%'.request()->input('search').'%');
            $data['search'] = request()->input('search');
        }

        $data['products'] = $builder->paginate(perPage: 249)
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

                if ($product->extra_korting === 'null' || $product->extra_korting === '0') {
                    $product->extra_korting = '';
                }

                return $product;
            });

        $merken = DB::select("SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(`values`, '$.common.merk')) AS brand FROM products WHERE `values`->'$.common.merk' IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(`values`, '$.common.merk')) != 'null'");
        $data['brands'] = ['--- KIES EEN MERK ---'];
        $data['brands'] = array_merge($data['brands'], array_column($merken, 'brand'));
        $data['current_brand'] = request()->input('brand');

        return view('admin::tools.product-stock-editor', $data);
    }

    public function update(Request $request)
    {
        $productData = $request->input('product', []);
        $products = Product::whereIn('id', array_keys($productData))->get();
        $parents = [];

        /**
         * SKUs whose extra korting changed. Saving the percentage does not
         * change `prijs` — CompetitorPricingService owns that field — so
         * without an immediate recompute the shop and Bol would keep the old
         * price until the nightly run, and the discount would look like it
         * silently did nothing.
         */
        $repriced = [];
        foreach ($products as $product) {
            $data = $productData[$product->id];
            if (is_null($product)) {
                continue;
            }

            $parents[$product->parent_id] = $product->parent_id;

            $values = $product->values;
            $keys = [
                'voorraad_eurogros',
                'voorraad_5_korting_handmatig',
                'voorraad_hw_5_korting',
                'uitverkoop_15_korting',
                'extra_korting',
            ];

            $allEqual = true;

            foreach ($keys as $key) {
                $left = (int) ($values['common'][$key] ?? null);
                $right = (int) ($data[$key] ?? null);

                if ($left !== $right) {
                    $allEqual = false;
                    break;
                }
            }

            if ($allEqual) {
                continue;
            }

            $values['common']['voorraad_eurogros'] = (int) $data['voorraad_eurogros'];
            $values['common']['voorraad_5_korting_handmatig'] = (int) $data['voorraad_5_korting_handmatig'];
            $values['common']['voorraad_hw_5_korting'] = (int) $data['voorraad_hw_5_korting'];
            $values['common']['uitverkoop_15_korting'] = (int) $data['uitverkoop_15_korting'];

            /**
             * Leeg betekent "geen handmatige korting", niet "0%": een 0 in de
             * JSON laat CompetitorPricingService even hard met rust, maar
             * vervuilt wel elk product met een veld dat niets doet.
             */
            $extraKorting = (int) ($data['extra_korting'] ?? 0);
            $previousKorting = (int) ($values['common']['extra_korting'] ?? 0);

            if ($extraKorting > 0) {
                $values['common']['extra_korting'] = $extraKorting;
            } else {
                unset($values['common']['extra_korting']);
            }

            if ($extraKorting !== $previousKorting) {
                $repriced[] = $product->sku;
            }

            $product->values = $values;
            $product->save();

            Event::dispatch('catalog.product.update.after', $product);
            app(ProductService::class)->copyStockValuesOnderkleed($product);
        }

        $parents = Product::whereIn('id', $parents)->get();
        foreach ($parents as $parent) {
            Event::dispatch('catalog.product.update.after', $parent);
        }

        /**
         * Recompute AFTER the values are saved, so the service reads the new
         * percentage. It writes `prijs`, logs the price history and pushes the
         * result to WooCommerce and Bol itself, which is why this is one call
         * rather than another sync loop here.
         */
        if ($repriced !== []) {
            app(CompetitorPricingService::class)->recomputeForSkus($repriced);
        }

        if ($request->has('next_page')) {
            session()->flash('info', 'Producten bijgewerkt. Ga verder met de volgende producten.');
            $data = ['page' => $request->input('next_page'), 'brand' => $request->input('brand')];
            if ($request->has('search')) {
                $data['search'] = $request->input('search');
            }

            return response()->redirectToRoute('admin.tools.product-stock-editor.index', $data);
        } else {
            session()->flash('success', 'Producten bijgewerkt. Je hebt alle producten gehad.');

            return response()->redirectToRoute('admin.tools.product-stock-editor.index', ['page' => 1]);
        }

    }
}
