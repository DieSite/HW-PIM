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
use Illuminate\Database\Eloquent\Builder;
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
            'drafts'           => $drafts,
            'run'              => $request->filled('run') ? AiDescriptionRun::find((int) $request->query('run')) : null,
            'status'           => $status,
            'counts'           => $this->counts($request->query('run')),
            'rewritableCount'  => $this->rewritableQuery($request->query('run'), $status)->count(),
            'publishableCount' => $this->publishableQuery($request->query('run'))->count(),
            'flaggedCount'     => $this->publishableQuery($request->query('run'))
                ->where('status', AiDescriptionDraft::STATUS_PENDING)
                ->whereRaw('JSON_LENGTH(problems) > 0')
                ->count(),
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
        $this->dispatchRewrite($draft);

        return response()->json(['message' => 'Opnieuw schrijven is gestart. Ververs de pagina over een halve minuut.']);
    }

    /**
     * Rewrite every draft in the current view. Published and approved drafts
     * are left alone: a human already signed those off.
     */
    public function regenerateAll(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'run'    => ['nullable', 'integer'],
            'status' => ['required', 'string', 'in:pending,rejected,failed,all'],
        ]);

        $count = 0;

        $this->rewritableQuery($validated['run'] ?? null, $validated['status'])
            ->with('run:id,fields')
            ->chunkById(500, function ($drafts) use (&$count): void {
                foreach ($drafts as $draft) {
                    $this->dispatchRewrite($draft);
                    $count++;
                }
            });

        if ($count === 0) {
            session()->flash('warning', 'Er staan geen teksten in deze weergave die opnieuw geschreven kunnen worden.');

            return back();
        }

        session()->flash('success', "{$count} teksten worden opnieuw geschreven. Ververs de pagina over een paar minuten.");

        return back();
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

    /**
     * Approve every draft still waiting for review and publish it together
     * with the drafts that were already approved.
     */
    public function approveAndApplyAll(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'run' => ['nullable', 'integer'],
        ]);

        $draftIds = $this->publishableQuery($validated['run'] ?? null)->pluck('id')->all();

        if ($draftIds === []) {
            session()->flash('warning', 'Er staan geen teksten klaar om goed te keuren en te publiceren.');

            return back();
        }

        AiDescriptionDraft::query()
            ->whereIn('id', $draftIds)
            ->where('status', AiDescriptionDraft::STATUS_PENDING)
            ->update([
                'status'      => AiDescriptionDraft::STATUS_APPROVED,
                'reviewed_by' => auth()->guard('admin')->id(),
                'reviewed_at' => now(),
            ]);

        ApplyAiDescriptionsJob::dispatch($draftIds, true);

        $count = count($draftIds);
        session()->flash('success', "{$count} teksten zijn goedgekeurd en worden gepubliceerd en naar de webshop gestuurd.");

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
     * Rewrites the fields the draft holds; a failed draft has none, so it falls
     * back to what its run asked for, then to every configured field.
     */
    private function dispatchRewrite(AiDescriptionDraft $draft): void
    {
        GenerateProductDescriptionJob::dispatch(
            $draft->product_id,
            array_keys($draft->fields ?? []) ?: ($draft->run?->fields ?: array_keys((array) config('ai.fields'))),
            $draft->run_id,
        );
    }

    /**
     * @return Builder<AiDescriptionDraft>
     */
    private function rewritableQuery(mixed $runId, string $status): Builder
    {
        return AiDescriptionDraft::query()
            ->when($runId !== null, fn ($query) => $query->where('run_id', (int) $runId))
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->whereNotIn('status', [AiDescriptionDraft::STATUS_APPLIED, AiDescriptionDraft::STATUS_APPROVED]);
    }

    /**
     * Drafts that are waiting for review or already approved, and actually hold
     * texts to publish.
     *
     * @return Builder<AiDescriptionDraft>
     */
    private function publishableQuery(mixed $runId): Builder
    {
        return AiDescriptionDraft::query()
            ->when($runId !== null, fn ($query) => $query->where('run_id', (int) $runId))
            ->whereIn('status', [AiDescriptionDraft::STATUS_PENDING, AiDescriptionDraft::STATUS_APPROVED])
            ->whereNotNull('fields');
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
