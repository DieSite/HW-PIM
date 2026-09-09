<?php

namespace App\Console\Commands;

use App\Models\CompetitorPrice;
use App\Models\CompetitorPriceRemoval;
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

    /**
     * SKUs of "Met onderkleed" variants seen this run, as a lookup.
     *
     * @var array<string, true>
     */
    private array $underlay = [];

    public function handle(CompetitorPricingService $pricing): int
    {
        $dbPath = $this->option('db') ?: config('competitor_pricing.db_path');

        if (! is_string($dbPath) || ! file_exists($dbPath)) {
            $this->error("Competitor SQLite database not found at: {$dbPath}");

            return self::FAILURE;
        }

        $rows = $this->withoutUnderlayVariants($this->readScrapedPrices($dbPath));

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
                $this->logRemovals($chunk->all());

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
     * Record what pruning is about to delete.
     *
     * The deletion is the news, not a side effect: a rug that loses its only
     * competitor goes straight back to the adviesverkoopprijs, and once the row
     * is gone nothing is left to explain why. The log lets the daily report
     * name the couplings that disappeared.
     *
     * @param  array<int, int>  $ids
     */
    private function logRemovals(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $prices = CompetitorPrice::whereIn('id', $ids)->get(['sku', 'shop', 'price', 'url', 'scraped_at']);

        // Een met-onderkleed-rij die verdwijnt is geen verloren koppeling maar
        // de opruiming van iets dat er nooit had moeten staan. Zonder deze
        // grens meldt het rapport de eenmalige schoonmaak van ~4.900 rijen als
        // "kleden die we niet meer bij de concurrent vinden".
        //
        // In één keer opgezocht: een pruned rij komt per definitie niet in de
        // scrape voor, dus de set uit withoutUnderlayVariants dekt hem niet.
        $onbekend = $prices->pluck('sku')->unique()->reject(fn (string $sku): bool => isset($this->underlay[$sku]))->values()->all();
        $this->underlay += $this->underlaySkus($onbekend);

        $removals = $prices
            ->reject(fn (CompetitorPrice $price): bool => isset($this->underlay[$price->sku]))
            ->map(fn (CompetitorPrice $price): array => [
                'sku'        => $price->sku,
                'shop'       => $price->shop,
                'price'      => $price->price,
                'url'        => $price->url,
                'scraped_at' => $price->scraped_at,
                'removed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        if ($removals !== []) {
            CompetitorPriceRemoval::insert($removals);
        }
    }

    /**
     * Drop every scraped row that belongs to a "Met onderkleed" variant.
     *
     * No competitor sells the rug bundled with an underlay, so such a price is
     * always the bare rug's page coupled to our bundle — which is exactly why
     * CompetitorCatalogExporter leaves those variants out of the catalog and
     * CompetitorPricingService::recompute() refuses to price them. What was
     * missing was this end: rows scraped before that rule existed are still in
     * the scraper database with their original scraped_at, so every run
     * re-imported them and they aged forever without ever being re-confirmed.
     * They drove no price (recompute skips them) but made up 40% of the table,
     * which is what pushed the reported refresh rate down to a quarter.
     *
     * Skipping them here also removes them: `--prune` deletes stored prices the
     * scrape no longer reports, so the leftovers disappear on the next run.
     *
     * @param  array<int, array{sku: string, shop: string, price: float, url: ?string, scraped_at: ?string}>  $rows
     * @return array<int, array{sku: string, shop: string, price: float, url: ?string, scraped_at: ?string}>
     */
    private function withoutUnderlayVariants(array $rows): array
    {
        $underlaySkus = $this->underlaySkus(array_unique(array_column($rows, 'sku')));
        $this->underlay = $underlaySkus;

        if ($underlaySkus === []) {
            return $rows;
        }

        $kept = array_values(array_filter(
            $rows,
            fn (array $row): bool => ! isset($underlaySkus[$row['sku']]),
        ));

        $this->info((count($rows) - count($kept)).' met-onderkleed prijzen overgeslagen (die variant heeft geen eigen concurrentprijs).');

        return $kept;
    }

    /**
     * The subset of these SKUs that is a "Met onderkleed" variant, as a lookup.
     *
     * The attribute is read in PHP rather than through a JSON path because the
     * legacy rows with a double-encoded `values` column return NULL for a path,
     * and a met-onderkleed variant would then slip through as an ordinary one.
     *
     * @param  array<int, string>  $skus
     * @return array<string, true>
     */
    private function underlaySkus(array $skus): array
    {
        $underlay = [];

        foreach (array_chunk($skus, 500) as $chunk) {
            Product::query()
                ->whereIn('sku', $chunk)
                ->select(['sku', 'values'])
                ->get()
                ->each(function (Product $product) use (&$underlay): void {
                    $values = $product->values;

                    if (is_string($values)) {
                        $values = json_decode($values, true);
                    }

                    if (is_array($values) && (($values['common']['onderkleed'] ?? null) === 'Met onderkleed')) {
                        $underlay[$product->sku] = true;
                    }
                });
        }

        return $underlay;
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

        // "Vanaf € 359,00" is the price of the competitor's SMALLEST size, not
        // of the size we asked for (the scraper marks shops without per-size
        // prices this way and the Excel leaves those cells uncoloured for the
        // same reason). Importing it as a firm price would undercut us to a
        // from-price for every size.
        if (preg_match('/^\s*vanaf\b/i', $value) === 1) {
            return null;
        }

        $number = preg_replace('/[^0-9,.]/', '', $value);
        $number = str_replace('.', '', $number);   // thousands separator
        $number = str_replace(',', '.', $number);   // decimal separator

        return is_numeric($number) ? (float) $number : null;
    }
}
