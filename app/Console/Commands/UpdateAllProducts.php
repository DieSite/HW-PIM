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
        $amount = Product::whereNull('parent_id')->count();
        $this->output->progressStart($amount);

        Product::whereNull('parent_id')->chunk(100, function ($parents) {
            foreach ($parents as $parent) {
                Event::dispatch('catalog.product.update.after', $parent);
                foreach ($parent->variants as $variant) {
                    Event::dispatch('catalog.product.update.after', $variant);
                }
                $this->output->progressAdvance();
            }
        });

        $this->output->progressFinish();
    }
}
