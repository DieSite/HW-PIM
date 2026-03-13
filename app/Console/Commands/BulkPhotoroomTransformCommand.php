<?php

namespace App\Console\Commands;

use App\Jobs\ApplyPhotoroomTransformationJob;
use App\Models\Product;
use Illuminate\Console\Command;
use Webkul\Attribute\Repositories\AttributeRepository;

class BulkPhotoroomTransformCommand extends Command
{
    protected $signature = 'photoroom:bulk-transform
                            {--dry-run : Show how many products would be processed without dispatching jobs}';

    protected $description = 'Dispatch Photoroom AI logo removal jobs for all in-stock parent products. Respects the 60 images/minute rate limit by staggering job delays.';

    public function handle(AttributeRepository $attributeRepository): void
    {
        $targetAttributes = $attributeRepository->findWhere([['ai_transformation_from', '!=', null]]);

        if ($targetAttributes->isEmpty()) {
            $this->warn('No attributes with ai_transformation_from configured. Nothing to do.');

            return;
        }

        $this->line('Target attributes: '.$targetAttributes->pluck('code')->join(', '));

        // Images live on parent (configurable) products, only fetch parents that have at least one in-stock variant.
        $parentIds = Product::whereNull('parent_id')
            ->whereHas('variants', fn ($q) => $q->inStock())
            ->pluck('id');

        if ($parentIds->isEmpty()) {
            $this->info('No in-stock parent products found.');

            return;
        }

        $totalJobs = $parentIds->count() * $targetAttributes->count();

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$parentIds->count()} parent product(s) × {$targetAttributes->count()} attribute(s) = {$totalJobs} job(s) would be dispatched.");
            $estimatedMinutes = (int) ceil($totalJobs / 60);
            $this->info("Estimated completion time: ~{$estimatedMinutes} minute(s).");

            return;
        }

        $this->info("Dispatching {$totalJobs} job(s) for {$parentIds->count()} parent product(s)...");

        $index = 0;

        foreach ($targetAttributes as $attribute) {
            foreach ($parentIds as $productId) {
                // Stagger: allow 60 jobs per minute window.
                $delaySeconds = (int) floor($index / 60) * 60;

                ApplyPhotoroomTransformationJob::dispatch($productId, $attribute->code)
                    ->delay(now()->addSeconds($delaySeconds));

                $index++;
            }
        }

        $estimatedMinutes = (int) ceil($index / 60);
        $this->info("Done. {$index} job(s) queued. Estimated completion time: ~{$estimatedMinutes} minute(s).");
    }
}
