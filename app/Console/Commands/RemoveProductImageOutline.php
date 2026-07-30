<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ProductImageEditor\PrimaryImageEditorService;
use App\Services\ProductService;
use Illuminate\Console\Command;

class RemoveProductImageOutline extends Command
{
    /**
     * @var string
     */
    protected $signature = 'products:remove-image-outline
        {--product-id=* : Only process these product ids}
        {--limit= : Stop after this many changed products}
        {--dry-run : Classify and report without writing anything}
        {--sync-wc : Trigger a WooCommerce sync for every changed product afterwards}';

    /**
     * @var string
     */
    protected $description = 'Remove the black shape outline from primary product images (re-composite from the original source when possible, pixel-strip the current composite otherwise)';

    public function handle(PrimaryImageEditorService $service, ProductService $productService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $productIds = array_filter($this->option('product-id'));

        $query = Product::query()->orderBy('id');

        if ($productIds !== []) {
            $query->whereIn('id', $productIds);
        }

        $totals = [
            PrimaryImageEditorService::OUTCOME_REAPPLIED  => 0,
            PrimaryImageEditorService::OUTCOME_STRIPPED   => 0,
            PrimaryImageEditorService::OUTCOME_NO_RING    => 0,
            PrimaryImageEditorService::OUTCOME_MANUAL     => 0,
            PrimaryImageEditorService::OUTCOME_NOT_MASKED => 0,
            'failed'                                      => 0,
        ];

        /** @var array<int, array{id: int, sku: string, shape: string, reason: string}> $manual */
        $manual = [];

        /** @var array<int, int> $changedIds */
        $changedIds = [];

        $bar = $this->output->createProgressBar((clone $query)->count());

        $query->chunkById(100, function ($products) use ($service, $dryRun, $limit, &$totals, &$manual, &$changedIds, $bar): ?bool {
            foreach ($products as $product) {
                $bar->advance();

                try {
                    $result = $service->removeOutline($product, $dryRun);
                } catch (\Throwable $e) {
                    $totals['failed']++;
                    $this->newLine();
                    $this->error("Product {$product->id} ({$product->sku}): {$e->getMessage()}");

                    continue;
                }

                $totals[$result['outcome']] = ($totals[$result['outcome']] ?? 0) + 1;

                if ($result['outcome'] === PrimaryImageEditorService::OUTCOME_MANUAL) {
                    $manual[] = [
                        'id'     => (int) $product->id,
                        'sku'    => (string) $product->sku,
                        'shape'  => $result['shape'] ?? '',
                        'reason' => $result['reason'] ?? '',
                    ];
                }

                if (in_array($result['outcome'], [PrimaryImageEditorService::OUTCOME_REAPPLIED, PrimaryImageEditorService::OUTCOME_STRIPPED], true)) {
                    $changedIds[] = (int) $product->id;

                    if ($limit > 0 && count($changedIds) >= $limit) {
                        return false;
                    }
                }
            }

            return null;
        });

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            '%s%d re-composited from source, %d pixel-stripped, %d already borderless, %d skipped (not a masked shape), %d need manual handling, %d failed.',
            $dryRun ? '[dry-run] ' : '',
            $totals[PrimaryImageEditorService::OUTCOME_REAPPLIED],
            $totals[PrimaryImageEditorService::OUTCOME_STRIPPED],
            $totals[PrimaryImageEditorService::OUTCOME_NO_RING],
            $totals[PrimaryImageEditorService::OUTCOME_NOT_MASKED],
            $totals[PrimaryImageEditorService::OUTCOME_MANUAL],
            $totals['failed'],
        ));

        if ($manual !== []) {
            $this->newLine();
            $this->warn('Products needing manual handling:');
            $this->table(['ID', 'SKU', 'Shape', 'Reason'], $manual);
        }

        if (! $dryRun && $this->option('sync-wc') && $changedIds !== []) {
            $this->syncChangedProducts($productService, $changedIds);
        }

        return $totals['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Queue a WooCommerce sync for every changed product, deduplicated per
     * parent so a parent with several changed variants syncs once.
     *
     * @param  array<int, int>  $changedIds
     */
    private function syncChangedProducts(ProductService $productService, array $changedIds): void
    {
        $parents = Product::query()
            ->with('parent')
            ->whereIn('id', $changedIds)
            ->get()
            ->map(static fn (Product $product) => $product->parent ?? $product)
            ->unique('id')
            ->values();

        foreach ($parents as $parent) {
            try {
                $productService->triggerWCSyncForParent($parent);
            } catch (\Throwable $e) {
                $this->error("WC sync for parent {$parent->id} ({$parent->sku}) failed: {$e->getMessage()}");
            }
        }

        $this->info("WooCommerce sync queued for {$parents->count()} parent product(s).");
    }
}
