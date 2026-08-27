<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiDescriptionRunRequest;
use App\Jobs\ApplyAiDescriptionsJob;
use App\Jobs\GenerateAiDescriptionsJob;
use App\Jobs\GenerateProductDescriptionJob;
use App\Models\AiDescriptionDraft;
use App\Models\AiDescriptionRun;
use App\Models\Product;
use App\Services\AI\AiDescriptionService;
use App\Services\AI\AiSettings;
use App\Services\AI\ProductDescriptionGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Tools → AI-teksten: filter, sample, run, review, publish.
 *
 * Generation and review are deliberately separate. A run touches thousands of
 * live product pages, so the model's output lands in drafts and a human decides
 * per product what goes to the shop.
 */
class AiDescriptionsController extends Controller
{
    public function __construct(
        private readonly AiDescriptionService $descriptions,
        private readonly ProductDescriptionGenerator $generator,
        private readonly AiSettings $settings,
    ) {}

    public function index(): View
    {
        return view('admin::tools.ai-descriptions', [
            'brands'      => $this->descriptions->brands(),
            'collections' => $this->descriptions->collections(),
            'fields'      => (array) config('ai.fields'),
            'enabled'     => $this->settings->enabled(),
            'driver'      => $this->settings->driver(),
            'runs'        => AiDescriptionRun::query()->latest('id')->limit(10)->get(),
        ]);
    }

    /**
     * Generate a handful of real texts before committing to a full run, so the
     * prompt can be judged on actual output rather than on intent.
     */
    public function preview(AiDescriptionRunRequest $request): JsonResponse
    {
        $filters = $request->filters();
        $fields = $request->fields();

        $products = $this->descriptions->matchingQuery($filters)
            ->inRandomOrder()
            ->limit(3)
            ->get(['id', 'sku', 'values']);

        if ($products->isEmpty()) {
            return response()->json(['count' => 0, 'samples' => []]);
        }

        $samples = [];

        foreach ($products as $product) {
            try {
                $result = $this->generator->generate($product, $fields);

                $samples[] = [
                    'sku'        => (string) $product->sku,
                    'before'     => $this->currentTexts($product, $fields),
                    'after'      => $result['texts'],
                    'problems'   => $result['problems'],
                    'similarity' => $result['similarity'],
                ];
            } catch (Throwable $exception) {
                $samples[] = [
                    'sku'   => (string) $product->sku,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return response()->json([
            'count'   => $this->descriptions->matchingQuery($filters)->count(),
            'samples' => $samples,
        ]);
    }

    public function run(AiDescriptionRunRequest $request): RedirectResponse
    {
        $filters = $request->filters();
        $matched = $this->descriptions->matchingQuery($filters)->count();

        if ($matched === 0) {
            session()->flash('warning', 'Geen producten komen overeen met deze filters.');

            return redirect()->route('admin.tools.ai-descriptions.index');
        }

        $run = AiDescriptionRun::create([
            'user_id'       => auth()->guard('admin')->id(),
            'filters'       => $filters,
            'fields'        => $request->fields(),
            'sync_woo'      => $request->boolean('sync_woo', true),
            'driver'        => $this->settings->driver(),
            'matched_count' => $matched,
            'status'        => 'queued',
        ]);

        GenerateAiDescriptionsJob::dispatch($run->id);

        session()->flash('success', "AI-teksten worden geschreven voor {$matched} producten. Beoordeel ze straks bij Concepten.");

        return redirect()->route('admin.tools.ai-descriptions.review', ['run' => $run->id]);
    }

    public function review(Request $request): View
    {
        $status = (string) $request->query('status', AiDescriptionDraft::STATUS_PENDING);

        $drafts = AiDescriptionDraft::query()
            ->with('product:id,sku,values')
            ->when($request->filled('run'), fn ($query) => $query->where('run_id', (int) $request->query('run')))
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->orderByDesc('similarity')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('admin::tools.ai-descriptions-review', [
            'drafts' => $drafts,
            'run'    => $request->filled('run') ? AiDescriptionRun::find((int) $request->query('run')) : null,
            'status' => $status,
            'counts' => $this->counts($request->query('run')),
        ]);
    }

    /**
     * Approve or reject one draft.
     */
    public function decide(Request $request, AiDescriptionDraft $draft): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:approve,reject'],
        ]);

        $draft->update([
            'status' => $validated['decision'] === 'approve'
                ? AiDescriptionDraft::STATUS_APPROVED
                : AiDescriptionDraft::STATUS_REJECTED,
            'reviewed_by' => auth()->guard('admin')->id(),
            'reviewed_at' => now(),
        ]);

        return response()->json(['status' => $draft->status]);
    }

    /**
     * Rewrite one draft, keeping it in review.
     */
    public function regenerate(AiDescriptionDraft $draft): JsonResponse
    {
        GenerateProductDescriptionJob::dispatch(
            $draft->product_id,
            array_keys($draft->fields ?? []) ?: array_keys((array) config('ai.fields')),
            $draft->run_id,
        );

        return response()->json(['message' => 'Opnieuw schrijven is gestart. Ververs de pagina over een halve minuut.']);
    }

    public function apply(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'run'      => ['nullable', 'integer'],
            'sync_woo' => ['nullable', 'boolean'],
        ]);

        $draftIds = AiDescriptionDraft::query()
            ->where('status', AiDescriptionDraft::STATUS_APPROVED)
            ->when(! empty($validated['run']), fn ($query) => $query->where('run_id', (int) $validated['run']))
            ->pluck('id')
            ->all();

        if ($draftIds === []) {
            session()->flash('warning', 'Er staan geen goedgekeurde teksten klaar om te publiceren.');

            return back();
        }

        ApplyAiDescriptionsJob::dispatch($draftIds, $request->boolean('sync_woo', true));

        $count = count($draftIds);
        session()->flash('success', "{$count} teksten worden gepubliceerd en naar de webshop gestuurd.");

        return back();
    }

    public function revert(AiDescriptionDraft $draft): JsonResponse
    {
        try {
            $this->descriptions->revert($draft);
        } catch (Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'De oude tekst staat weer op het product.']);
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, string>
     */
    private function currentTexts(Product $product, array $fields): array
    {
        $values = $this->descriptions->values($product);
        $texts = [];

        foreach ($fields as $code) {
            $texts[$code] = (string) ($values['common'][$code] ?? '');
        }

        return $texts;
    }

    /**
     * @return array<string, int>
     */
    private function counts(mixed $runId): array
    {
        return AiDescriptionDraft::query()
            ->when($runId !== null, fn ($query) => $query->where('run_id', (int) $runId))
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
    }
}
