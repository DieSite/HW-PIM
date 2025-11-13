<?php

namespace App\Console\Commands;

use App\Services\ProductService;
use Illuminate\Console\Command;
use Webkul\Product\Models\Product;

class UpdateAllProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-all-products {--skip=} {--sku=}';

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
        $builder = Product::whereNull('parent_id');

        if ($this->option('sku')) {
            $builder = $builder->where('sku', 'LIKE', $this->option('sku').'%');
        }

        $amount = $builder->count();
        $this->output->progressStart($amount);

        $skip = $this->option('skip') ?? 0;
        $count = 0;

        $builder->chunk(100, function ($products) use (&$count, $skip) {
            foreach ($products as $product) {
                if ($count++ < $skip) {
                    $this->output->progressAdvance();

                    continue;
                }
                app(ProductService::class)->triggerWCSyncForParent($product);
                $this->output->progressAdvance();
            }
        });

        $this->output->progressFinish();
    }
}
