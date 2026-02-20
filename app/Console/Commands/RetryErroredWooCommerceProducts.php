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
        $query = ProductModel::whereNotNull('additional');

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $erroredIds = $query->pluck('id');

        if ($erroredIds->isEmpty()) {
            $this->info('No errored products found.');

            return;
        }

        // Resolve each errored product to its root parent, deduplicate
        $parentIds = collect();

        Product::whereIn('id', $erroredIds)
            ->chunk(100, function ($products) use (&$parentIds) {
                foreach ($products as $product) {
                    $parentIds->push($product->parent_id ?? $product->id);
                }
            });

        $parentIds = $parentIds->unique()->values();

        $this->info("Retrying sync for {$parentIds->count()} parent product(s) (from {$erroredIds->count()} errored record(s)).");
        $this->output->progressStart($parentIds->count());

        Product::whereIn('id', $parentIds)
            ->with('variants')
            ->chunk(50, function ($parents) use ($productService) {
                foreach ($parents as $parent) {
                    $productService->triggerWCSyncForParent($parent);
                    $this->output->progressAdvance();
                }
            });

        $this->output->progressFinish();
    }
}