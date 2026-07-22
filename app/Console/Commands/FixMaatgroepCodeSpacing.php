<?php

namespace App\Console\Commands;

use App\Jobs\SyncProductWithBolComJob;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;

class FixMaatgroepCodeSpacing extends Command
{
    const OLD_CODE = '200 cm x 300cm';

    const NEW_CODE = '200 cm x 300 cm';

    /**
     * @var string
     */
    protected $signature = 'fix:maatgroep-code-spacing {--dry-run : Report what would change without writing} {--no-sync : Skip queuing WooCommerce/Bol.com sync jobs for repaired products}';

    /**
     * @var string
     */
    protected $description = 'Rename the typo\'d "'.self::OLD_CODE.'" maatgroep attribute option code to "'.self::NEW_CODE.'" and repair matching product values';

    public function handle(ProductService $productService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $attribute = Attribute::where('code', 'maatgroep')->first();

        if (! $attribute) {
            $this->error('No attribute with code "maatgroep" found.');

            return self::FAILURE;
        }

        $option = AttributeOption::where('attribute_id', $attribute->id)
            ->where('code', self::OLD_CODE)
            ->first();

        if ($option) {
            $conflict = AttributeOption::where('attribute_id', $attribute->id)
                ->where('code', self::NEW_CODE)
                ->first();

            if ($conflict) {
                $this->error('An attribute option with code "'.self::NEW_CODE.'" already exists for the "maatgroep" attribute. Aborting.');

                return self::FAILURE;
            }

            $this->info(($dryRun ? '[dry-run] ' : '').'Renaming attribute option code "'.self::OLD_CODE.'" to "'.self::NEW_CODE.'".');

            if (! $dryRun) {
                $option->code = self::NEW_CODE;
                $option->save();
            }
        } else {
            $this->info('No attribute option with code "'.self::OLD_CODE.'" found; skipping option rename.');
        }

        $total = DB::table('products')->count();
        $bar = $this->output->createProgressBar($total);
        $scanned = 0;
        $fixedIds = [];

        DB::table('products')->select(['id', 'values'])->orderBy('id')->chunkById(1000, function ($rows) use (&$scanned, &$fixedIds, $dryRun, $bar): void {
            foreach ($rows as $row) {
                $scanned++;
                $bar->advance();

                $values = json_decode((string) $row->values, true);

                $depth = 0;
                while (is_string($values) && $depth < 5) {
                    $values = json_decode($values, true);
                    $depth++;
                }

                if (! is_array($values)) {
                    continue;
                }

                if (($values['common']['maatgroep'] ?? null) !== self::OLD_CODE) {
                    continue;
                }

                $values['common']['maatgroep'] = self::NEW_CODE;
                $fixedIds[] = $row->id;

                if (! $dryRun) {
                    DB::table('products')->where('id', $row->id)->update(['values' => json_encode($values)]);
                }
            }
        });

        $bar->finish();
        $this->newLine();

        $fixed = count($fixedIds);
        $this->info(($dryRun ? '[dry-run] ' : '')."Scanned {$scanned} products; ".($dryRun ? 'would repair' : 'repaired')." {$fixed} products with maatgroep = \"".self::OLD_CODE.'".');

        if ($fixed > 0 && ! $dryRun) {
            if ($this->option('no-sync')) {
                $this->warn('Repaired rows were written directly (no sync events fired) and --no-sync was passed. Re-run WooCommerce/Bol sync manually if these products need to be pushed.');
            } else {
                $this->syncRepairedProducts($fixedIds, $productService);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, int>  $productIds
     */
    private function syncRepairedProducts(array $productIds, ProductService $productService): void
    {
        $products = Product::whereIn('id', $productIds)
            ->with('bolComCredentials')
            ->get();

        $wcRootIds = $products
            ->map(fn (Product $product) => $product->parent_id ?? $product->id)
            ->unique()
            ->values();

        $this->info("Queuing WooCommerce sync for {$wcRootIds->count()} parent product(s)...");

        Product::whereIn('id', $wcRootIds)->each(function (Product $root) use ($productService): void {
            $productService->triggerWCSyncForParent($root);
        });

        $bolDispatched = 0;
        foreach ($products as $product) {
            if (! $product->bol_com_sync) {
                continue;
            }

            foreach ($product->bolComCredentials as $credential) {
                SyncProductWithBolComJob::dispatch($product, $credential, true, null, false);
                $bolDispatched++;
            }
        }

        $this->info("Queued {$bolDispatched} Bol.com sync job(s).");
    }
}
