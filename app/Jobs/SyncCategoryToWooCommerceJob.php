<?php

namespace App\Jobs;

use App\Jobs\Middleware\DisconnectsIdleRedis;
use App\Services\WooCommerce\WooCommerceCategorySync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webkul\Category\Models\Category;

/**
 * Creates a category saved in the admin in WooCommerce right away, so
 * products in it show up there without a manual category export.
 */
class SyncCategoryToWooCommerceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $categoryId) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new DisconnectsIdleRedis()];
    }

    public function handle(WooCommerceCategorySync $categorySync): void
    {
        $category = Category::query()->find($this->categoryId);

        if ($category) {
            $categorySync->ensure($category);
        }
    }
}
