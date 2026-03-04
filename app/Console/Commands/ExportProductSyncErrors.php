<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExportProductSyncErrors extends Command
{
    protected $signature = 'export:product-sync-errors';

    protected $description = 'Export all unique product_sync_error values, normalizing away SKU differences';

    public function handle(): int
    {
        $rows = DB::table('products')
            ->whereRaw("JSON_EXTRACT(additional, '$.product_sync_error') IS NOT NULL")
            ->selectRaw("REPLACE(JSON_UNQUOTE(JSON_EXTRACT(additional, '$.product_sync_error')), sku, '{SKU}') AS normalized_error")
            ->get()
            ->countBy('normalized_error')
            ->sortDesc();

        if ($rows->isEmpty()) {
            $this->info('No products with sync errors found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Count', 'Error'],
            $rows->map(fn (int $count, string $error) => [$count, $error])->values()->all()
        );

        $this->info("Total unique error types: {$rows->count()} (across {$rows->sum()} products)");

        return self::SUCCESS;
    }
}