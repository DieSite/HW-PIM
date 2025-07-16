<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Webkul\Product\Models\Product;
use Webkul\Product\Repositories\ProductRepository;

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
    public function handle(ProductRepository $productRepository)
    {

        foreach ($productRepository->whereNull('parent_id')->get() as $parent) {
            Event::dispatch('catalog.product.update.after', $parent);
            foreach ($parent->variants as $variant) {
                Event::dispatch('catalog.product.update.after', $variant);
            }
        }
    }
}
