<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Webkul\Product\Models\Product;

class UpdateAllProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-all-products';

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
        $amount = $builder->count();
        $this->output->progressStart($amount);

        $builder->chunk(100, function ($products) {
            foreach ($products as $product) {
                Event::dispatch('catalog.product.update.after', $product);
                foreach ($product->variants as $variant) {
                    Event::dispatch('catalog.product.update.after', $variant);
                }
                $this->output->progressAdvance();
            }
        });

        $this->output->progressFinish();
    }
}
