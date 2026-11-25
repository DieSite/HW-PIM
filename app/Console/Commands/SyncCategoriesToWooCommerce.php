<?php

namespace App\Console\Commands;

use App\Services\WooCommerce\WooCommerceCategorySync;
use Illuminate\Console\Command;
use Throwable;
use Webkul\Category\Models\Category;

class SyncCategoriesToWooCommerce extends Command
{
    protected $signature = 'woocommerce:sync-categories {--dry-run : Only list the categories without a WooCommerce mapping}
        {--verify : Also check mapped categories against the shop and replace mappings whose category was deleted there}';

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

            if ($category->code === 'root') {
                continue;
            }

            if ($mapped) {
                if ($this->option('verify') && ! $this->option('dry-run')) {
                    $failed = ! $this->verify($categorySync, $category) || $failed;
                }

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

    /**
     * Re-check a mapped category in the shop; reports when its mapping was replaced.
     */
    private function verify(WooCommerceCategorySync $categorySync, Category $category): bool
    {
        $before = $categorySync->mappedExternalId($category->code);

        try {
            $after = $categorySync->ensure($category, verify: true);
        } catch (Throwable $e) {
            report($e);
            $this->error("{$category->code}: {$e->getMessage()}");

            return false;
        }

        if ($after !== $before) {
            $this->warn("{$category->code}: WooCommerce category {$before} no longer exists → now {$after}");
        }

        return true;
    }
}
