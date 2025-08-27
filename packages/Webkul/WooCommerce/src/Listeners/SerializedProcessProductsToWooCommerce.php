<?php

namespace Webkul\WooCommerce\Listeners;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webkul\Product\Models\Product;

class SerializedProcessProductsToWooCommerce implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly Product $product
    ) {
        $this->product->withoutRelations();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (is_null($this->product->parent)) {
            $this->product->load('variants');
        } else {
            $this->product->load('parent');
        }
        ProcessProductsToWooCommerce::dispatchSync($this->product->toArray());
    }
}
