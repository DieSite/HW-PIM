<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class BackfillAdviesverkoopprijsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'pricing:backfill-adviesverkoopprijs {--force : Overwrite an existing adviesverkoopprijs}';

    /**
     * @var string
     */
    protected $description = 'Seed the adviesverkoopprijs (price ceiling) from the current prijs for every product.';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $updated = 0;

        Product::query()
            ->whereNotNull('values->common->prijs')
            ->chunkById(500, function (Collection $products) use ($force, &$updated): void {
                foreach ($products as $product) {
                    $values = $product->values;
                    $prijs = $values['common']['prijs'] ?? null;

                    if (empty($prijs['EUR'])) {
                        continue;
                    }

                    $hasAdvies = ! empty($values['common']['adviesverkoopprijs']['EUR']);

                    if ($hasAdvies && ! $force) {
                        continue;
                    }

                    $values['common']['adviesverkoopprijs'] = $prijs;
                    $product->values = $values;
                    $product->saveQuietly();

                    $updated++;
                }
            });

        $this->info("Backfilled adviesverkoopprijs for {$updated} products.");

        return self::SUCCESS;
    }
}
