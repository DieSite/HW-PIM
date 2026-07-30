<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\AfwerkingOptieService;
use App\Services\ProductService;
use Illuminate\Console\Command;

class ExportAfwerkingenToWooCommerce extends Command
{
    protected $signature = 'wc:export-afwerkingen
                            {--dry-run : Show how many products would be synced without actually syncing}
                            {--limit= : Maximum number of parent products to process}';

    protected $description = 'Sync every parent product that offers afwerkingsmogelijkheden to WooCommerce';

    public function handle(ProductService $productService, AfwerkingOptieService $afwerkingen): void
    {
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        /**
         * Eligibility depends on the admin settings and the manual override, so
         * it cannot be expressed as a query — the candidates are narrowed down
         * to maatwerk parents in SQL and then filtered in PHP.
         */
        $ids = collect();

        Product::whereNull('parent_id')
            ->whereHas('variants', fn ($query) => $query->maatwerk())
            ->with('variants')
            ->chunkById(200, function ($products) use ($afwerkingen, $ids, $limit): bool {
                foreach ($products as $product) {
                    if (! $afwerkingen->isBeschikbaar($product)) {
                        continue;
                    }

                    $ids->push($product->id);

                    if ($limit && $ids->count() >= $limit) {
                        return false;
                    }
                }

                return true;
            });

        if ($ids->isEmpty()) {
            $this->info('No products offering afwerkingen found.');

            return;
        }

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$ids->count()} parent product(s) offering afwerkingen would be synced to WooCommerce.");

            return;
        }

        $this->info("Syncing {$ids->count()} parent product(s) offering afwerkingen to WooCommerce...");
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
