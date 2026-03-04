<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Product\Models\Product;

class ExportProductSyncErrors extends Command
{
    protected $signature = 'export:product-sync-errors';

    protected $description = 'Export all unique product_sync_error values, normalizing away SKU differences';

    public function handle(): int
    {
        $this->info('Loading SKUs for normalization...');

        // Load all SKUs sorted longest-first to prevent partial replacements
        // (e.g. "SKU-123-A" matched before "SKU-123").
        $skus = Product::query()
            ->whereNotNull('sku')
            ->pluck('sku')
            ->sortByDesc(fn (string $sku) => strlen($sku))
            ->values();

        if ($skus->isEmpty()) {
            $this->warn('No products found.');

            return self::SUCCESS;
        }

        $skuPattern = $skus
            ->map(fn (string $sku) => preg_quote($sku, '/'))
            ->implode('|');

        $this->info('Fetching sync errors...');

        $errors = collect();

        Product::query()
            ->whereNotNull('additional')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(additional, '$.product_sync_error')) IS NOT NULL")
            ->select(['additional'])
            ->chunk(500, function ($products) use ($skuPattern, &$errors) {
                foreach ($products as $product) {
                    $raw = $product->additional['product_sync_error'] ?? null;

                    if (! is_string($raw) || $raw === '') {
                        continue;
                    }

                    $normalized = preg_replace("/{$skuPattern}/", '{SKU}', $raw);

                    $errors->push($normalized);
                }
            });

        if ($errors->isEmpty()) {
            $this->info('No products with sync errors found.');

            return self::SUCCESS;
        }

        $grouped = $errors->countBy()->sortDesc();

        $rows = $grouped
            ->map(fn (int $count, string $error) => [$count, $error])
            ->values()
            ->all();

        $this->table(['Count', 'Error'], $rows);

        $this->info("Total unique error types: {$grouped->count()} (across {$errors->count()} products)");

        return self::SUCCESS;
    }
}