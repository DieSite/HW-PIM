<?php

namespace App\Console\Commands;

use App\Services\WooCommerce\WooCommerceCategorySync;
use Illuminate\Console\Command;
use Throwable;
use Webkul\Category\Models\Category;

class SyncCategoriesToWooCommerce extends Command
{
    protected $signature = 'woocommerce:sync-categories {--dry-run : Only list the categories without a WooCommerce mapping}';

    protected $description = 'Create every PIM category that has no WooCommerce mapping yet in WooCommerce';

    public function handle(WooCommerceCategorySync $categorySync): int
    {
        $failed = false;

        foreach (Category::query()->orderBy('id')->get() as $category) {
            $mapped = $categorySync->isMapped($category->code);

            if ($mapped === null) {
                $this->error('No default WooCommerce credential is configured.');

                return self::FAILURE;
            }

            if ($mapped || $category->code === 'root') {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("Would create {$category->code}");

                continue;
            }

            try {
                $this->info("{$category->code} → WooCommerce category {$categorySync->ensure($category)}");
            } catch (Throwable $e) {
                $failed = true;
                report($e);
                $this->error("{$category->code}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
