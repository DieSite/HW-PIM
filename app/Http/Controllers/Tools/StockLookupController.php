<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\StockLookupService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The "eenvoudige modus": a read-only product lookup for showroom and office
 * staff, outside the full PIM layout. Grant a role only the `simple-stock`
 * permission to give them nothing but these screens.
 */
class StockLookupController extends Controller
{
    public function index(Request $request, StockLookupService $lookup): View
    {
        $query = trim((string) $request->query('q', ''));
        $filter = $this->filter($request);

        $results = $lookup->search($query, $filter);

        return view('admin::simple-stock.index', [
            'query'     => $query,
            'filter'    => $filter,
            'searched'  => $query !== '' || $filter !== StockLookupService::FILTER_ALL,
            'rugs'      => $results['rugs'],
            'truncated' => $results['truncated'],
            'search'    => $this->searchParameters($request),
            'canUsePim' => bouncer()->hasPermission('dashboard'),
        ]);
    }

    /**
     * One rug with all its details. Only the index route is in the ACL, so
     * the permission is checked here as well.
     */
    public function show(Request $request, int $rug, StockLookupService $lookup): View
    {
        abort_unless(bouncer()->hasPermission('simple-stock'), 401, 'This action is unauthorized.');

        $product = Product::query()->whereNull('parent_id')->findOrFail($rug);

        return view('admin::simple-stock.show', [
            'rug'       => $lookup->detail($product),
            'search'    => $this->searchParameters($request),
            'canEdit'   => bouncer()->hasPermission('catalog.products.edit'),
            'canUsePim' => bouncer()->hasPermission('dashboard'),
        ]);
    }

    private function filter(Request $request): string
    {
        return in_array($request->query('voorraad'), StockLookupService::FILTERS, true)
            ? $request->query('voorraad')
            : StockLookupService::FILTER_ALL;
    }

    /**
     * The search a detail page came from, so "terug" returns to the same results.
     *
     * @return array{q?: string, voorraad?: string}
     */
    private function searchParameters(Request $request): array
    {
        return array_filter([
            'q'        => trim((string) $request->query('q', '')),
            'voorraad' => $this->filter($request) === StockLookupService::FILTER_ALL ? '' : $this->filter($request),
        ], fn (string $value): bool => $value !== '');
    }
}
