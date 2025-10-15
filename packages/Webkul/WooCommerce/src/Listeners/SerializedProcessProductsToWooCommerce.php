<?php

namespace Webkul\WooCommerce\Listeners;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;
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

    public function failed(Throwable $exception): void
    {
        if ($exception instanceof ModelNotFoundException) {
            // Log dat het product niet meer bestaat, maar markeer de job niet als mislukt
            \Log::info('Product bestaat niet meer voor WooCommerce synchronisatie', [
                'product_id' => $this->product->id,
            ]);

            return;
        }

        // Gooi andere exceptions gewoon door
        throw $exception;
    }
}
