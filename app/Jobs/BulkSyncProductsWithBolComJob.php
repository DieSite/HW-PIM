<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webkul\Product\Repositories\ProductRepository;

class BulkSyncProductsWithBolComJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected ?int $batchSize = 100,
        protected ?array $productIds = null,
        protected ?int $credentialId = 1
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ProductRepository $productRepository)
    {
        $query = $productRepository->getModel()->newQuery()
            ->whereJsonDoesntContain('values->common->ean', null)
            ->whereJsonDoesntContain('values->common->ean', '')
            ->where('bol_com_sync', true)
            ->whereHas('bolComCredentials', function ($query) {
                $query->where('bol_com_credentials.id', $this->credentialId);
            });

        if ($this->productIds) {
            $query->whereIn('id', $this->productIds);
        }

        $productIds = $query->take($this->batchSize)->pluck('id')->toArray();

        $totalProducts = count($productIds);

        if ($totalProducts === 0) {
            return;
        }

        $products = $productRepository->getModel()->whereIn('id', $productIds)->get();
        foreach ($products as $product) {
            $product->bol_com_credential_id = $this->credentialId;
            $product->bol_com_sync = true;
            $product->saveQuietly();

            $ean = $product->values['common']['ean'] ?? null;

            if (empty($ean)) {
                continue;
            }

            $credential = $product->bolComCredentials()->where('bol_com_credentials.id', $this->credentialId)->first();

            SyncProductWithBolComJob::dispatch($product, $credential, true);
        }

        if ($query->count() > $totalProducts) {
            self::dispatch($this->batchSize, null, $this->credentialId);
        }
    }
}
