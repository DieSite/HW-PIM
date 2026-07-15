<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkEditRequest;
use App\Jobs\BulkEditProductsJob;
use App\Models\BulkEditRun;
use App\Services\BulkEditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class BulkEditController extends Controller
{
    public function __construct(private BulkEditService $bulkEditService) {}

    public function index(): View
    {
        return view('admin::tools.bulk-edit', [
            'brands'     => $this->bulkEditService->brands(),
            'attributes' => $this->bulkEditService->editableAttributes(),
        ]);
    }

    public function preview(BulkEditRequest $request): JsonResponse
    {
        return response()->json(
            $this->bulkEditService->preview($request->filters(), $request->operation())
        );
    }

    public function apply(BulkEditRequest $request): RedirectResponse
    {
        $filters = $request->filters();
        $operation = $request->operation();
        $syncWoo = $request->boolean('sync_woo');

        $matched = $this->bulkEditService->affectedQuery($filters, $operation)->count();

        if ($matched === 0) {
            session()->flash('warning', 'Geen producten komen overeen met deze bewerking.');

            return redirect()->route('admin.tools.bulk-edit.index');
        }

        $run = BulkEditRun::create([
            'user_id'          => auth()->guard('admin')->id(),
            'target_attribute' => $operation['target'],
            'filters'          => $filters,
            'operation'        => $operation,
            'sync_woo'         => $syncWoo,
            'matched_count'    => $matched,
            'status'           => 'queued',
        ]);

        BulkEditProductsJob::dispatch($filters, $operation, $syncWoo, $run->id);

        session()->flash('success', "Bulkbewerking gestart voor {$matched} producten. Je krijgt de wijzigingen op de achtergrond verwerkt.");

        return redirect()->route('admin.tools.bulk-edit.index');
    }
}
