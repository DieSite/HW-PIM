<?php

namespace App\Console\Commands;

use App\Jobs\ApplyPhotoroomTransformationJob;
use App\Models\Product;
use Illuminate\Console\Command;
use Webkul\Attribute\Repositories\AttributeRepository;

class BulkPhotoroomTransformCommand extends Command
{
    protected $signature = 'photoroom:bulk-transform
                            {--dry-run : Show how many products would be processed without dispatching jobs}
                            {--without-result : Only process products that do not yet have a value for the target attribute}';

    protected $description = 'Dispatch Photoroom AI logo removal jobs for all in-stock parent products. Respects the 60 images/minute rate limit by staggering job delays.';

    public function handle(AttributeRepository $attributeRepository): void
    {
        $targetAttributes = $attributeRepository->findWhere([['ai_transformation_from', '!=', null]]);

        if ($targetAttributes->isEmpty()) {
            $this->warn('No attributes with ai_transformation_from configured. Nothing to do.');

            return;
        }

        $this->line('Target attributes: '.$targetAttributes->pluck('code')->join(', '));

        $filterWithoutResult = $this->option('without-result');

        // Images live on parent (configurable) products, only fetch parents that have at least one in-stock variant.
        $parentProducts = Product::whereNull('parent_id')
            ->whereHas('variants', fn ($q) => $q->inStock())
            ->when($filterWithoutResult, fn ($q) => $q->select(['id', 'values']))
            ->when(! $filterWithoutResult, fn ($q) => $q->select(['id']))
            ->get();

        if ($parentProducts->isEmpty()) {
            $this->info('No in-stock parent products found.');

            return;
        }

        $index = 0;
        $pairs = [];

        foreach ($targetAttributes as $attribute) {
            foreach ($parentProducts as $product) {
                if ($filterWithoutResult) {
                    $existingValue = $attribute->getValueFromProductValues($product->values ?? [], '', '');

                    if (! empty($existingValue)) {
                        continue;
                    }
                }

                $pairs[] = [$product->id, $attribute->code];
            }
        }

        $totalJobs = count($pairs);

        if ($totalJobs === 0) {
            $this->info('No products need processing.');

            return;
        }

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$totalJobs} job(s) would be dispatched.");
            $estimatedMinutes = (int) ceil($totalJobs / 60);
            $this->info("Estimated completion time: ~{$estimatedMinutes} minute(s).");

            return;
        }

        $this->info("Dispatching {$totalJobs} job(s)...");

        foreach ($pairs as [$productId, $attributeCode]) {
            // Stagger: allow 60 jobs per minute window.
            $delaySeconds = (int) floor($index / 60) * 60;

            ApplyPhotoroomTransformationJob::dispatch($productId, $attributeCode)
                ->delay(now()->addSeconds($delaySeconds));

            $index++;
        }

        $estimatedMinutes = (int) ceil($index / 60);
        $this->info("Done. {$index} job(s) queued. Estimated completion time: ~{$estimatedMinutes} minute(s).");
    }
}
