<?php

namespace Webkul\WooCommerce\Listeners;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webkul\Product\Models\Product;
use Webkul\Product\Repositories\ProductRepository;

class SerializedProcessProductsToWooCommerce implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private Product $product
    ) {
        $this->product->withoutRelations();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->product = app(ProductRepository::class)->find($this->product->id);

        if (is_null($this->product->parent)) {
            $this->product->load('variants');
        } else {
            $this->product->load('parent');
        }
        ProcessProductsToWooCommerce::dispatchSync($this->product->toArray());
    }
}
