<?php

namespace App\Console\Commands;

use App\Services\ProductValuesRepairer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixDoubleEncodedProductValues extends Command
{
    /**
     * @var string
     */
    protected $signature = 'fix:double-encoded-product-values {--dry-run : Report what would change without writing}';

    /**
     * @var string
     */
    protected $description = 'Repair products whose `values` JSON column was double-encoded by an earlier import';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $total = DB::table('products')->count();
        $bar = $this->output->createProgressBar($total);
        $scanned = 0;
        $fixed = 0;

        DB::table('products')->select(['id', 'values'])->orderBy('id')->chunkById(1000, function ($rows) use (&$scanned, &$fixed, $dryRun, $bar): void {
            foreach ($rows as $row) {
                $scanned++;
                $bar->advance();

                $repaired = ProductValuesRepairer::fix($row->values);
                if ($repaired === null) {
                    continue;
                }

                $fixed++;
                if (! $dryRun) {
                    DB::table('products')->where('id', $row->id)->update(['values' => $repaired]);
                }
            }
        });

        $bar->finish();
        $this->newLine();

        $this->info(($dryRun ? '[dry-run] ' : '')."Scanned {$scanned} products; ".($dryRun ? 'would repair' : 'repaired')." {$fixed} double-encoded values.");

        if ($fixed > 0 && ! $dryRun) {
            $this->warn('Repaired rows were written directly (no sync events fired). Re-run WooCommerce/Bol sync if these products need to be pushed.');
        }

        return self::SUCCESS;
    }
}
