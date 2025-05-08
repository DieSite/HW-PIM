<?php

namespace App\Jobs;

use App\Clients\BolApiClient;
use App\Services\BolComProductService;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Webkul\Product\Models\Product;
use Webkul\Product\Repositories\ProductRepository;

class SyncProductWithBolComJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 5;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected Product $product,
        protected bool $previousSyncState = false,
        protected ?string $processId = null
    ) {}

    /**
     * Execute the job.
     *
     * @throws Exception|GuzzleException
     */
    public function handle(BolComProductService $bolComProductService, BolApiClient $apiClient, ProductRepository $productRepository)
    {
        try {
            if ($this->processId !== null) {
                $this->checkProcessStatus($apiClient, $productRepository);

                return;
            }

            $response = $bolComProductService->syncProduct($this->product, $this->previousSyncState);

            if ($response === null) {
                return;
            }

            if (! empty($response['processStatusId']) && $response['status'] !== 'SUCCESS') {
                self::dispatch($this->product, $this->previousSyncState, $response['processStatusId'])
                    ->delay(now()->addSeconds(30));
            }
        } catch (Exception $e) {
            Log::error('Failed to sync product with Bol.com in job', [
                'product_id' => $this->product->id,
                'sku'        => $this->product->sku,
                'error'      => $e->getMessage(),
            ]);

            throw new Exception('Failed to sync with Bol.com in job: '.$e->getMessage());
        }
    }

    /**
     * Check the process status with Bol.com API
     *
     *
     * @throws Exception|GuzzleException
     */
    protected function checkProcessStatus(BolApiClient $apiClient, ProductRepository $productRepository): void
    {
        $response = $apiClient->get('/shared/process-status/'.$this->processId);

        if (! isset($response['status'])) {
            throw new Exception('Invalid response when checking process status');
        }

        switch ($response['status']) {
            case 'PENDING':
                self::dispatch($this->product, $this->previousSyncState, $this->processId)
                    ->delay(now()->addSeconds(30));
                break;

            case 'SUCCESS':
                if (! empty($response['entityId'])) {
                    $product = $productRepository->find($this->product->id);
                    $product->bol_com_reference = $response['entityId'];
                    $product->save();
                }
                break;

            case 'FAILURE':
                $errorMessage = $response['errorMessage'] ?? 'Unknown error during Bol.com sync process';
                Log::error('Bol.com sync process failed', [
                    'product_id' => $this->product->id,
                    'process_id' => $this->processId,
                    'error'      => $errorMessage,
                ]);

                throw new Exception('Bol.com sync process failed: '.$errorMessage);
            default:
                Log::warning('Unknown status from Bol.com process', [
                    'product_id' => $this->product->id,
                    'process_id' => $this->processId,
                    'status'     => $response['status'],
                ]);
        }
    }
}
