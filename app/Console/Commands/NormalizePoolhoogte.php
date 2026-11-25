<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\PoolhoogteNormalizer;
use App\Services\ProductService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rewrites the existing poolhoogte values in the normalised format of
 * PoolhoogteNormalizer and queues the changed products for WooCommerce. New
 * saves are normalised by the saving hook already.
 *
 * The rows are written directly, so the other saving hooks (delivery times,
 * andere vormen) do not run for a change that is only cosmetic.
 */
class NormalizePoolhoogte extends Command
{
    /**
     * @var string
     */
    protected $signature = 'products:normalize-poolhoogte
        {--dry-run : Report what would change without writing}
        {--no-sync : Skip queuing WooCommerce sync jobs for the changed products}';

    /**
     * @var string
     */
    protected $description = 'Rewrite every poolhoogte value as "<number> mm" (e.g. "25mm" → "25 mm") and resync the changed products';

    public function handle(PoolhoogteNormalizer $normalizer, ProductService $productService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        /** @var array<string, array{to: string, count: int}> $changes */
        $changes = [];
        /** @var array<string, int> $unreadable */
        $unreadable = [];
        $changedIds = [];

        DB::table('products')->select(['id', 'values'])->orderBy('id')->chunkById(1000, function ($rows) use ($normalizer, $dryRun, &$changes, &$unreadable, &$changedIds): void {
            foreach ($rows as $row) {
                $values = $this->decodeValues($row->values);

                if (! is_array($values) || ! array_key_exists(PoolhoogteNormalizer::ATTRIBUTE, $values['common'] ?? [])) {
                    continue;
                }

                $current = $values['common'][PoolhoogteNormalizer::ATTRIBUTE];

                if ($current === null || $current === '') {
                    continue;
                }

                $normalized = $normalizer->normalize($current);

                if ($normalized === $current) {
                    if (! $normalizer->isReadable($current)) {
                        $key = is_scalar($current) ? (string) $current : json_encode($current);
                        $unreadable[$key] = ($unreadable[$key] ?? 0) + 1;
                    }

                    continue;
                }

                $from = (string) $current;
                $changes[$from] ??= ['to' => $normalized, 'count' => 0];
                $changes[$from]['count']++;
                $changedIds[] = $row->id;

                if (! $dryRun) {
                    $values['common'][PoolhoogteNormalizer::ATTRIBUTE] = $normalized;
                    DB::table('products')->where('id', $row->id)->update(['values' => json_encode($values)]);
                }
            }
        });

        $prefix = $dryRun ? '[dry-run] ' : '';

        if ($changes !== []) {
            ksort($changes, SORT_NATURAL);
            $this->table(['Poolhoogte', 'Wordt', 'Producten'], collect($changes)->map(fn (array $change, string $from): array => [$from, $change['to'], $change['count']])->values()->all());
        }

        $this->info($prefix.($dryRun ? 'Would normalise' : 'Normalised').' the poolhoogte of '.count($changedIds).' product(s).');

        if ($unreadable !== []) {
            $this->warn('Left unchanged because they cannot be read as a height:');
            $this->table(['Poolhoogte', 'Producten'], collect($unreadable)->map(fn (int $count, string $value): array => [$value, $count])->values()->all());
        }

        if ($changedIds !== [] && ! $dryRun) {
            if ($this->option('no-sync')) {
                $this->warn('--no-sync was passed: resync these products to WooCommerce manually.');
            } else {
                $this->syncChangedProducts($changedIds, $productService);
            }
        }

        return self::SUCCESS;
    }

    /**
     * The values column, also when it was stored double-encoded.
     */
    private function decodeValues(?string $raw): mixed
    {
        $values = json_decode((string) $raw, true);

        for ($depth = 0; is_string($values) && $depth < 5; $depth++) {
            $values = json_decode($values, true);
        }

        return $values;
    }

    /**
     * Queue the parents of the changed products for WooCommerce. Bol.com is
     * left alone: it only reads the digits of the poolhoogte (laag- or
     * hoogpolig), which normalising does not change.
     *
     * @param  array<int, int>  $productIds
     */
    private function syncChangedProducts(array $productIds, ProductService $productService): void
    {
        $rootIds = Product::query()->whereIn('id', $productIds)->get(['id', 'parent_id'])
            ->map(fn (Product $product): int => $product->parent_id ?? $product->id)
            ->unique()
            ->values();

        $this->info("Queuing WooCommerce sync for {$rootIds->count()} parent product(s)...");

        Product::query()->whereIn('id', $rootIds)->each(function (Product $root) use ($productService): void {
            $productService->triggerWCSyncForParent($root);
        });
    }
}
