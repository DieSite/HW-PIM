<?php

namespace App\Console\Commands;

use App\Services\WooCommerce\WooCommerceSyncEventRecorder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Webkul\Product\Models\Product;
use Webkul\WooCommerce\Listeners\SerializedProcessProductsToWooCommerce;

/**
 * Removes disabled variants from WooCommerce. Until 2026-10-21 the sync
 * updated a disabled variant like any other, so variants disabled before then
 * are still live in the shop.
 */
class RemoveInactiveVariantsFromWooCommerce extends Command
{
    protected $signature = 'wc:remove-inactive-variants
                            {--sku=* : Only these parent or variant SKUs}
                            {--dry-run : List the variants that would be removed without removing them}
                            {--limit= : Maximum number of parent products to process}';

    protected $description = 'Delete disabled variants from WooCommerce';

    public function handle(WooCommerceSyncEventRecorder $syncEventRecorder): int
    {
        $variantsByParent = $this->inactiveVariantsByParent();

        if ($variantsByParent->isEmpty()) {
            $this->info('No disabled variants found.');

            return self::SUCCESS;
        }

        $variantCount = $variantsByParent->flatten(1)->count();

        if ($this->option('dry-run')) {
            $this->table(['Parent', 'Disabled variants'], $variantsByParent->map(fn (Collection $variants, string $parentSku): array => [
                $parentSku,
                $variants->pluck('sku')->implode(', '),
            ])->values()->all());
            $this->info("Dry run: {$variantCount} disabled variant(s) of {$variantsByParent->count()} parent(s) would be removed.");

            return self::SUCCESS;
        }

        foreach ($variantsByParent as $variants) {
            $parent = $variants->first()->parent;

            $syncEventRecorder->queued($parent);
            $variants->each(fn (Product $variant) => $syncEventRecorder->queued($variant));

            /**
             * The parent goes first so it stops offering the sizes of the
             * variants deleted after it. One chain per parent, the way
             * triggerWCSyncForParent() queues variants.
             */
            SerializedProcessProductsToWooCommerce::dispatch($parent, $variants->pluck('id')->all());
        }

        $this->info("Queued {$variantCount} disabled variant(s) of {$variantsByParent->count()} parent(s) for removal from WooCommerce.");

        return self::SUCCESS;
    }

    /**
     * @return Collection<string, Collection<int, Product>> Keyed by parent SKU
     */
    private function inactiveVariantsByParent(): Collection
    {
        $parentIds = $this->inactiveVariants()
            ->distinct()
            ->orderBy('parent_id')
            ->when($this->option('limit'), fn (Builder $query, string $limit) => $query->limit((int) $limit))
            ->pluck('parent_id');

        return $this->inactiveVariants()
            ->with('parent')
            ->whereIn('parent_id', $parentIds)
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Product $variant): string => $variant->parent->sku);
    }

    /**
     * Disabled variants, narrowed to the --sku option when given: a parent
     * SKU selects all its disabled variants, a variant SKU just that one.
     */
    private function inactiveVariants(): Builder
    {
        $skus = $this->option('sku');

        return Product::query()
            ->whereNotNull('parent_id')
            ->where('status', 0)
            ->when($skus, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereIn('sku', $skus)
                ->orWhereHas('parent', fn (Builder $parent) => $parent->whereIn('sku', $skus))));
    }
}
