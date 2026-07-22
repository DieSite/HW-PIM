<?php

namespace App\Console\Commands;

use App\Models\CompetitorPrice;
use App\Models\Product;
use App\Services\CompetitorPricingService;
use Illuminate\Console\Command;

class ImportCompetitorPricesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'pricing:import-competitor-prices
                            {--db= : Path to the scraper SQLite database (defaults to config)}
                            {--no-recompute : Only import competitor prices, skip price recomputation}
                            {--prune : Delete competitor prices that no longer exist in the scraper database}';

    /**
     * @var string
     */
    protected $description = 'Import competitor prices from the scraper SQLite DB and recompute our selling prices.';

    public function handle(CompetitorPricingService $pricing): int
    {
        $dbPath = $this->option('db') ?: config('competitor_pricing.db_path');

        if (! is_string($dbPath) || ! file_exists($dbPath)) {
            $this->error("Competitor SQLite database not found at: {$dbPath}");

            return self::FAILURE;
        }

        $rows = $this->readScrapedPrices($dbPath);

        if ($rows === []) {
            $this->warn('No usable competitor prices found in the database.');

            return self::SUCCESS;
        }

        $staleIds = $this->option('prune') ? $this->stalePriceIds($rows) : collect();

        $touchedSkus = array_values(array_unique(array_merge(
            array_column($rows, 'sku'),
            CompetitorPrice::whereIn('id', $staleIds)->pluck('sku')->all(),
        )));

        $previousSnapshot = $this->currentSnapshot($touchedSkus);
        $productIds = Product::whereIn('sku', $touchedSkus)->pluck('id', 'sku');

        $this->info(count($rows).' competitor prices across '.count($touchedSkus).' SKUs. Upserting…');

        foreach (array_chunk($rows, 500) as $chunk) {
            foreach ($chunk as $row) {
                CompetitorPrice::updateOrCreate(
                    ['sku' => $row['sku'], 'shop' => $row['shop']],
                    [
                        'product_id' => $productIds[$row['sku']] ?? null,
                        'price'      => $row['price'],
                        'url'        => $row['url'],
                        'scraped_at' => $row['scraped_at'],
                    ],
                );
            }
        }

        if ($staleIds->isNotEmpty()) {
            $deleted = 0;

            foreach ($staleIds->chunk(500) as $chunk) {
                $deleted += CompetitorPrice::whereIn('id', $chunk->all())->delete();
            }

            $this->info("Pruned {$deleted} competitor prices that are no longer in the scraper database.");
        }

        if ($this->option('no-recompute')) {
            $this->info('Import done (recompute skipped).');

            return self::SUCCESS;
        }

        $this->info('Recomputing selling prices…');
        $pricing->recomputeForSkus($touchedSkus, $previousSnapshot);

        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * Read and normalize the real (€) competitor prices from the scraper DB.
     *
     * @return array<int, array{sku: string, shop: string, price: float, url: ?string, scraped_at: ?string}>
     */
    private function readScrapedPrices(string $dbPath): array
    {
        $pdo = new \PDO('sqlite:'.$dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $statement = $pdo->query('SELECT sku, shop, price_str, url, scraped_at FROM prices');

        $rows = [];

        while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
            $price = $this->parseMoney($row['price_str'] ?? '');

            if ($price === null || $price <= 0) {
                continue;
            }

            $rows[] = [
                'sku'        => (string) $row['sku'],
                'shop'       => (string) $row['shop'],
                'price'      => $price,
                'url'        => $row['url'] ?: null,
                'scraped_at' => $row['scraped_at'] ?: null,
            ];
        }

        return $rows;
    }

    /**
     * IDs of stored competitor prices whose (sku, shop) pair no longer has a
     * real price in the scraper database.
     *
     * @param  array<int, array{sku: string, shop: string}>  $rows
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function stalePriceIds(array $rows): \Illuminate\Support\Collection
    {
        $scraped = [];

        foreach ($rows as $row) {
            $scraped[$row['sku'].'|'.$row['shop']] = true;
        }

        return CompetitorPrice::query()
            ->get(['id', 'sku', 'shop'])
            ->reject(fn (CompetitorPrice $price): bool => isset($scraped[$price->sku.'|'.$price->shop]))
            ->pluck('id')
            ->values();
    }

    /**
     * Snapshot the competitor prices we already have for these SKUs, so the
     * pricing service can explain what changed.
     *
     * @param  array<int, string>  $skus
     * @return array<string, array<string, array{price: float, url: ?string}>>
     */
    private function currentSnapshot(array $skus): array
    {
        $snapshot = [];

        CompetitorPrice::whereIn('sku', $skus)
            ->get(['sku', 'shop', 'price', 'url'])
            ->each(function (CompetitorPrice $price) use (&$snapshot): void {
                $snapshot[$price->sku][$price->shop] = [
                    'price' => (float) $price->price,
                    'url'   => $price->url,
                ];
            });

        return $snapshot;
    }

    /**
     * Parse a Dutch-formatted money string like "€ 1.159,00" into 1159.00.
     * Returns null for non-price values such as "n.v.t.".
     */
    private function parseMoney(string $value): ?float
    {
        if (! str_contains($value, '€')) {
            return null;
        }

        $number = preg_replace('/[^0-9,.]/', '', $value);
        $number = str_replace('.', '', $number);   // thousands separator
        $number = str_replace(',', '.', $number);   // decimal separator

        return is_numeric($number) ? (float) $number : null;
    }
}
