<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Jobs\ImportVoorraadDeMunkJob;
use App\Models\DeMunkProductLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DeMunkStockController extends Controller
{
    public function index(): View
    {
        $snapshot = Cache::get(ImportVoorraadDeMunkJob::SNAPSHOT_CACHE_KEY);

        $runningSince = DB::table('jobs')
            ->where('payload', 'like', '%ImportVoorraadDeMunkJob%')
            ->min('created_at');

        return view('admin::tools.demunk-voorraad', [
            'links'      => $this->linkRows(),
            'unmatched'  => $snapshot['unmatched'] ?? [],
            'importedAt' => isset($snapshot['imported_at'])
                ? Carbon::parse($snapshot['imported_at'])->timezone('Europe/Amsterdam')
                : null,
            'articleCount' => $snapshot['article_count'] ?? null,
            'runningSince' => $runningSince
                ? Carbon::createFromTimestamp((int) $runningSince)->timezone('Europe/Amsterdam')
                : null,
        ]);
    }

    public function import(): RedirectResponse
    {
        ImportVoorraadDeMunkJob::dispatch();

        session()->flash('success', 'De Munk voorraad-import is gestart. Dit duurt enkele minuten; de koppelingen en voorraad worden daarna bijgewerkt.');

        return redirect()->route('admin.tools.demunk-voorraad.index');
    }

    /**
     * Search linkable PIM design products by name or SKU (no codes to look up).
     */
    public function searchProducts(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        $rows = DB::table('products')
            ->selectRaw("id, sku,
                JSON_UNQUOTE(JSON_EXTRACT(`values`, '$.common.productnaam')) AS productnaam,
                JSON_UNQUOTE(JSON_EXTRACT(`values`, '$.common.vorm')) AS vorm")
            ->where('type', 'configurable')
            ->where('sku', 'like', config('demunk.brand_sku_prefix').'%')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(`values`, '$.common.vorm')) IN ('Rechthoek', 'Rond')")
            ->where(function ($query) use ($term) {
                $query->where('sku', 'like', "%{$term}%")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(`values`, '$.common.productnaam')) LIKE ?", ["%{$term}%"]);
            })
            ->limit(20)
            ->get();

        return response()->json($rows->map(fn ($row) => [
            'id'    => (int) $row->id,
            'sku'   => $row->sku,
            'label' => trim(($row->productnaam ?? $row->sku).' ('.$row->vorm.') — '.$row->sku),
        ]));
    }

    /**
     * Manually link a product to a De Munk article identity (or suppress it).
     * Manual links are locked so the auto-matcher never overwrites them.
     */
    public function link(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id'       => ['required', 'integer', 'exists:products,id'],
            'suppress'         => ['nullable', 'boolean'],
            'demunk_collectie' => ['required_without:suppress', 'nullable', 'string'],
            'demunk_kwaliteit' => ['required_without:suppress', 'nullable', 'string'],
            'demunk_kleur'     => ['required_without:suppress', 'nullable', 'string'],
        ]);

        $suppress = (bool) ($validated['suppress'] ?? false);

        DeMunkProductLink::query()->updateOrCreate(
            ['product_id' => $validated['product_id']],
            [
                'demunk_collectie' => $suppress ? null : strtoupper(trim($validated['demunk_collectie'])),
                'demunk_kwaliteit' => $suppress ? null : strtoupper(trim($validated['demunk_kwaliteit'])),
                'demunk_kleur'     => $suppress ? null : strtoupper(trim($validated['demunk_kleur'])),
                'demunk_vorm'      => null,
                'match_score'      => null,
                'source'           => 'manual',
                'locked'           => true,
            ],
        );

        session()->flash('success', $suppress
            ? 'Product gemarkeerd als "geen De Munk-bron". De voorraad wordt niet meer automatisch bijgewerkt.'
            : 'Koppeling opgeslagen. Voer een import uit om de voorraad te synchroniseren.');

        return redirect()->route('admin.tools.demunk-voorraad.index');
    }

    /**
     * Remove a link. Auto links may reappear on the next import if the
     * deterministic match still holds; use "geen bron" to suppress instead.
     */
    public function unlink(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'link_id' => ['required', 'integer', 'exists:demunk_product_links,id'],
        ]);

        DeMunkProductLink::query()->whereKey($validated['link_id'])->delete();

        session()->flash('success', 'Koppeling verwijderd.');

        return redirect()->route('admin.tools.demunk-voorraad.index');
    }

    /**
     * Build display rows for the current links, joined to product name/SKU.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function linkRows()
    {
        return DB::table('demunk_product_links as l')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->selectRaw("l.id, l.product_id, l.demunk_collectie, l.demunk_kwaliteit, l.demunk_kleur,
                l.source, l.locked, p.sku,
                JSON_UNQUOTE(JSON_EXTRACT(p.`values`, '$.common.productnaam')) AS productnaam")
            ->orderBy('l.demunk_collectie')
            ->orderBy('l.demunk_kwaliteit')
            ->orderBy('l.demunk_kleur')
            ->get();
    }
}
