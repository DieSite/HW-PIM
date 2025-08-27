<?php

namespace App\Console\Commands;

use App\Services\ProductService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Webkul\Product\Models\Product;
use Webkul\WooCommerce\Listeners\ProcessProductsToWooCommerce;

class UpdateProductsByFile extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-product-by-file';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $filePath = storage_path('app/private/products.csv');

        $file = fopen($filePath, 'r');
        $skus = [];

        while (($data = fgetcsv($file, 1000, ',')) !== false) {
            $skus[] = $data[0];
        }

        $builder = Product::whereIn('sku', $skus);

        $amount = $builder->count();
        $this->output->progressStart($amount);

        $builder->chunk(100, function ($products) {
            foreach ($products as $product) {
                app(ProductService::class)->triggerWCSyncForParent($product);
                $this->output->progressAdvance();
            }
        });

        $this->output->progressFinish();
    }
}
