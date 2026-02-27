<?php

namespace App\Console\Commands;

use App\Models\Product as ProductModel;
use App\Services\ProductService;
use Illuminate\Console\Command;
use Webkul\Product\Models\Product;

class RetryErroredWooCommerceProducts extends Command
{
    protected $signature = 'wc:retry-errored-products {--limit= : Maximum number of errored products to process}';

    protected $description = 'Retry WooCommerce sync for all products with errors (as shown in the errored products tool)';

    public function handle(ProductService $productService): void
    {
        // App\Models\Product extends Webkul\Product\Models\Product on the same table,
        // so we can resolve parent_id in one query instead of two.
        $erroredProducts = ProductModel::whereNotNull('additional')
            ->select(['id', 'parent_id'])
            ->when($this->option('limit'), fn ($q) => $q->limit((int) $this->option('limit')))
            ->get();

        if ($erroredProducts->isEmpty()) {
            $this->info('No errored products found.');

            return;
        }

        $parentIds = $erroredProducts
            ->map(fn ($p) => $p->parent_id ?? $p->id)
            ->unique()
            ->values();

        $this->info("Retrying sync for {$parentIds->count()} parent product(s) (from {$erroredProducts->count()} errored record(s)).");
        $this->output->progressStart($parentIds->count());

        // No need to eager-load variants — SerializedProcessProductsToWooCommerce
        // calls withoutRelations() in its constructor and re-fetches from DB in handle().
        Product::whereIn('id', $parentIds)
            ->chunk(50, function ($parents) use ($productService) {
                foreach ($parents as $parent) {
                    $productService->triggerWCSyncForParent($parent);
                    $this->output->progressAdvance();
                }
            });

        $this->output->progressFinish();
    }
}