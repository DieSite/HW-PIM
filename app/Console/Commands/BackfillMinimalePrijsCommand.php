<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\MinimalePrijsCalculator;
use App\Services\ProductService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class BackfillMinimalePrijsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'pricing:backfill-minimale-prijs
                            {--dry-run : Report what would change without writing anything}
                            {--force : Also overwrite a minimale prijs that is already filled}
                            {--limit= : Stop after this many maatwerk variants}
                            {--sync : Queue a WooCommerce sync for the parents of the updated variants}';

    /**
     * @var string
     */
    protected $description = 'Fill the empty minimale prijs of every maatwerk variant with its 100 x 100 cm (1 m²) price.';

    public function handle(MinimalePrijsCalculator $calculator, ProductService $productService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $updated = 0;
        $skipped = 0;
        $seen = 0;

        /** @var array<int, int> $parentIds */
        $parentIds = [];

        Product::query()
            ->maatwerk()
            ->chunkById(500, function (Collection $products) use ($calculator, $dryRun, $force, $limit, &$updated, &$skipped, &$seen, &$parentIds): bool {
                foreach ($products as $product) {
                    if ($limit !== null && $seen >= $limit) {
                        return false;
                    }

                    $seen++;

                    $values = $product->values;

                    if (! $force && ! empty($values['common']['minimale_prijs']['EUR'])) {
                        continue;
                    }

                    $price = $calculator->hundredByHundredPrice($product);

                    if ($price === null) {
                        $skipped++;
                        $this->line("<comment>{$product->sku}: no m² price, skipped</comment>");

                        continue;
                    }

                    $updated++;

                    if ($product->parent_id !== null) {
                        $parentIds[$product->parent_id] = $product->parent_id;
                    }

                    if ($dryRun) {
                        $this->line("{$product->sku}: minimale prijs → {$price}");

                        continue;
                    }

                    $values['common']['minimale_prijs'] = ['EUR' => $price];
                    $product->values = $values;
                    $product->saveQuietly();
                }

                return true;
            });

        if ($dryRun) {
            $this->info("Dry run: {$updated} maatwerk variant(s) would get a minimale prijs, {$skipped} skipped without an m² price.");

            return self::SUCCESS;
        }

        $this->info("Filled the minimale prijs of {$updated} maatwerk variant(s), skipped {$skipped} without an m² price.");

        if ($this->option('sync')) {
            $this->syncParents($productService, array_values($parentIds));
        }

        return self::SUCCESS;
    }

    /**
     * The storefront reads minimale-prijs off the parent product, so only the
     * parents of the updated variants have to go back to WooCommerce.
     *
     * @param  array<int, int>  $parentIds
     */
    private function syncParents(ProductService $productService, array $parentIds): void
    {
        if ($parentIds === []) {
            $this->info('No parents to sync.');

            return;
        }

        $this->info('Queueing a WooCommerce sync for '.count($parentIds).' parent product(s)...');

        Product::whereIn('id', $parentIds)
            ->with('variants')
            ->chunk(50, function (Collection $parents) use ($productService): void {
                foreach ($parents as $parent) {
                    $productService->triggerWCSyncForParent($parent);
                }
            });
    }
}
