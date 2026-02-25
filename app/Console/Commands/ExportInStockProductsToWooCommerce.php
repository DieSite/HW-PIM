<?php

namespace App\Console\Commands;

use App\Services\ProductService;
use Illuminate\Console\Command;
use Webkul\Product\Models\Product;

class ExportInStockProductsToWooCommerce extends Command
{
    protected $signature = 'wc:export-in-stock
                            {--dry-run : Show how many products would be synced without actually syncing}
                            {--limit= : Maximum number of parent products to process}';

    protected $description = 'Export all in-stock products to WooCommerce';

    public function handle(ProductService $productService): void
    {
        $query = Product::whereNull('parent_id')
            ->where(function ($q) {
                // Simple product with stock on itself
                $q->whereDoesntHave('variants')->inStock();
                // Variable product with at least one in-stock variant
                $q->orWhereHas('variants', fn ($vq) => $vq->inStock());
            });

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $ids = $query->pluck('id');

        if ($ids->isEmpty()) {
            $this->info('No in-stock products found.');

            return;
        }

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$ids->count()} parent product(s) in stock would be synced to WooCommerce.");

            return;
        }

        $this->info("Syncing {$ids->count()} in-stock parent product(s) to WooCommerce...");
        $this->output->progressStart($ids->count());

        Product::whereIn('id', $ids)
            ->with('variants')
            ->chunk(50, function ($products) use ($productService) {
                foreach ($products as $product) {
                    $productService->triggerWCSyncForParent($product);
                    $this->output->progressAdvance();
                }
            });

        $this->output->progressFinish();
        $this->info('Done.');
    }
}