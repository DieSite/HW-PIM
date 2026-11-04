<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompetitorPricesBulkRequest;
use App\Http\Requests\CompetitorPricesFilterRequest;
use App\Models\CompetitorPrice;
use App\Services\CompetitorDiscountOverview;
use App\Services\CompetitorPriceExclusionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Tools → Concurrentieprijzen: the sizes we sell at a deep discount, every
 * competitor price behind them, and excluding price points in bulk.
 */
class CompetitorPricesController extends Controller
{
    public function index(CompetitorPricesFilterRequest $request, CompetitorDiscountOverview $overview): View
    {
        $filters = $request->filters();
        $result = $overview->rugs($filters, $request->perPage());

        return view('admin::tools.competitor-prices', [
            'filters'       => $filters,
            'perPage'       => $request->perPage(),
            'pageSizes'     => CompetitorPricesFilterRequest::PAGE_SIZES,
            'rugs'          => $result['rugs'],
            'sizeCount'     => $result['sizes'],
            'excludedCount' => $result['excluded'],
            'brands'        => $overview->brands(),
            'shops'         => $overview->shops(),
            'canEdit'       => bouncer()->hasPermission('catalog.products.edit'),
            'maxAge'        => CompetitorPrice::maxAgeDays(),
        ]);
    }

    public function update(CompetitorPricesBulkRequest $request, CompetitorPriceExclusionService $exclusions): RedirectResponse
    {
        ['action' => $action, 'ids' => $ids] = $request->selection();

        $prices = CompetitorPrice::query()->whereIn('id', $ids)->get();

        if ($prices->isEmpty()) {
            return back()->with('warning', 'Die prijzen bestaan niet meer; misschien heeft de nachtelijke analyse ze net ververst.');
        }

        if ($action === 'exclude') {
            $result = $exclusions->exclude($prices, $request->validated('reason'), auth('admin')->id());
            $message = sprintf('%d %s uitgesloten', $result['prices'], $result['prices'] === 1 ? 'prijs' : 'prijzen');
        } else {
            $result = $exclusions->include($prices);
            $message = sprintf('%d %s telt weer mee', $result['prices'], $result['prices'] === 1 ? 'prijs' : 'prijzen');
        }

        $message .= $result['repriced'] > 0
            ? sprintf('; %d %s een nieuwe prijs, die naar de webshop en Bol gaat.', $result['repriced'], $result['repriced'] === 1 ? 'maat kreeg' : 'maten kregen')
            : '; onze prijzen bleven gelijk.';

        return back()->with('success', $message);
    }
}
