<?php

namespace App\Jobs;

use App\Models\BulkEditRun;
use App\Models\Product;
use App\Services\BulkEditService;
use App\Services\ProductService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;

class BulkEditProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;

    /**
     * Run on the dedicated long-running connection/queue (Horizon
     * supervisor-long): the shared "default" queue's retry_after is far below
     * this job's timeout, so a long bulk edit there would be re-reserved
     * mid-flight and fail with MaxAttemptsExceededException.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $operation
     */
    public function __construct(
        private array $filters,
        private array $operation,
        private bool $syncWoo,
        private int $runId,
    ) {
        $this->onConnection('redis-long');
        $this->onQueue('long');
    }

    public function handle(BulkEditService $bulkEditService, ProductService $productService): void
    {
        $run = BulkEditRun::find($this->runId);
        $run?->update(['status' => 'processing']);

        $target = $this->operation['target'];
        $changed = 0;

        /** @var array<int, true> $parentIds */
        $parentIds = [];

        $bulkEditService->affectedQuery($this->filters, $this->operation)
            ->select(['id', 'sku', 'parent_id', 'values'])
            ->chunkById(200, function ($products) use ($bulkEditService, $target, &$changed, &$parentIds) {
                foreach ($products as $product) {
                    $values = $bulkEditService->values($product);
                    $current = (string) ($values['common'][$target] ?? '');
                    $new = $bulkEditService->applyOperation($current, $this->operation);

                    if ($new === $current) {
                        continue;
                    }

                    $values['common'][$target] = $new;
                    $product->values = $values;
                    $product->save();

                    Event::dispatch('catalog.product.update.after', $product);

                    $parentIds[$product->parent_id ?? $product->id] = true;
                    $changed++;
                }
            });

        if ($this->syncWoo && $parentIds !== []) {
            Product::whereIn('id', array_keys($parentIds))
                ->chunkById(100, function ($parents) use ($productService) {
                    foreach ($parents as $parent) {
                        $productService->triggerWCSyncForParent($parent);
                    }
                });
        }

        $run?->update([
            'changed_count' => $changed,
            'status'        => 'completed',
            'finished_at'   => now(),
        ]);
    }
}
