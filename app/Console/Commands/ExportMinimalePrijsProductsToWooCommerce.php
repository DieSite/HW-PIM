<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Console\Command;

class ExportMinimalePrijsProductsToWooCommerce extends Command
{
    protected $signature = 'wc:export-minimale-prijs
                            {--dry-run : Show how many products would be synced without actually syncing}
                            {--limit= : Maximum number of parent products to process}';

    protected $description = 'Sync all parent products that carry a minimale prijs to WooCommerce';

    public function handle(ProductService $productService): void
    {
        $query = Product::whereNull('parent_id')
            ->where(function ($q) {
                // Simple product with a minimale prijs on itself
                $q->whereDoesntHave('variants')->hasMinimalePrijs();
                // Variable product with at least one variant carrying a minimale prijs
                $q->orWhereHas('variants', fn ($vq) => $vq->hasMinimalePrijs());
            });

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $ids = $query->pluck('id');

        if ($ids->isEmpty()) {
            $this->info('No products with a minimale prijs found.');

            return;
        }

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$ids->count()} parent product(s) with a minimale prijs would be synced to WooCommerce.");

            return;
        }

        $this->info("Syncing {$ids->count()} parent product(s) with a minimale prijs to WooCommerce...");
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
