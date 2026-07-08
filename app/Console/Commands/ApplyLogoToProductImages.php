<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ProductImageEditor\GalleryLogoService;
use Illuminate\Console\Command;

class ApplyLogoToProductImages extends Command
{
    /**
     * @var string
     */
    protected $signature = 'products:apply-logo-to-images
        {--product-id=* : Only process these product ids}
        {--dry-run : Report what would be stamped without writing}';

    /**
     * @var string
     */
    protected $description = 'Overlay the HW logo on every product gallery image that does not carry it yet';

    public function handle(GalleryLogoService $galleryLogoService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $productIds = array_filter($this->option('product-id'));

        $query = Product::query()->orderBy('id');

        if ($productIds !== []) {
            $query->whereIn('id', $productIds);
        }

        $totals = ['products' => 0, 'stamped' => 0, 'reused' => 0, 'failed' => 0];

        $bar = $this->output->createProgressBar((clone $query)->count());

        $query->chunkById(100, function ($products) use ($galleryLogoService, $dryRun, &$totals, $bar): void {
            foreach ($products as $product) {
                $bar->advance();

                try {
                    $summary = $galleryLogoService->apply($product, $dryRun);
                } catch (\Throwable $e) {
                    $totals['failed']++;
                    $this->newLine();
                    $this->error("Product {$product->id} ({$product->sku}): {$e->getMessage()}");

                    continue;
                }

                if ($summary['stamped'] === [] && $summary['reused'] === []) {
                    continue;
                }

                $totals['products']++;
                $totals['stamped'] += count($summary['stamped']);
                $totals['reused'] += count($summary['reused']);
            }
        });

        $bar->finish();
        $this->newLine();

        $this->info(sprintf(
            '%s%d products updated: %d images stamped, %d existing variants reused, %d products failed.',
            $dryRun ? '[dry-run] ' : '',
            $totals['products'],
            $totals['stamped'],
            $totals['reused'],
            $totals['failed'],
        ));

        return $totals['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
