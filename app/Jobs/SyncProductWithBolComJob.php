<?php

namespace App\Jobs;

use App\Clients\BolApiClient;
use App\Models\BolComCredential;
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
        protected BolComCredential $bolComCredential,
        protected bool $previousSyncState = false,
        protected ?string $processId = null,
        protected bool $unchecked = false,
    ) {}

    /**
     * Execute the job.
     *
     * @throws Exception|GuzzleException
     */
    public function handle(BolComProductService $bolComProductService, BolApiClient $apiClient, ProductRepository $productRepository)
    {
        try {
            if ($this->unchecked == true) {
                $bolComProductService->syncProduct($this->product, $this->bolComCredential, $this->previousSyncState, true);
            }

            if ($this->processId !== null) {
                $this->checkProcessStatus($apiClient, $productRepository, $bolComProductService);

                return;
            }

            $apiClient->setCredential($this->bolComCredential);

            $response = $bolComProductService->syncProduct($this->product, $this->bolComCredential, $this->previousSyncState);

            if ($response === null) {
                return;
            }

            if (! empty($response['processStatusId']) && $response['status'] !== 'SUCCESS') {
                self::dispatch($this->product, $this->bolComCredential, $this->previousSyncState, $response['processStatusId'])
                    ->delay(now()->addSeconds(30));
            }
        } catch (Exception $e) {
            Log::error('Failed to sync product with Bol.com in job', [
                'product_id' => $this->product->id,
                'sku'        => $this->product->sku,
                'error'      => $e->getMessage(),
            ]);


            throw new Exception('Failed to sync with Bol.com in job ', previous: $e);
        }
    }

    /**
     * Check the process status with Bol.com API
     *
     *
     * @throws Exception|GuzzleException
     */
    protected function checkProcessStatus(BolApiClient $apiClient, ProductRepository $productRepository, ?BolComProductService $bolComProductService = null): void
    {
        $apiClient->setCredential($this->bolComCredential);

        $response = $apiClient->get('/shared/process-status/'.$this->processId);

        if (! isset($response['status'])) {
            throw new Exception('Invalid response when checking process status');
        }

        switch ($response['status']) {
            case 'PENDING':
                self::dispatch($this->product, $this->bolComCredential, $this->previousSyncState, $this->processId)
                    ->delay(now()->addSeconds(30));
                break;

            case 'SUCCESS':
                if (! empty($response['entityId'])) {
                    $product = $productRepository->find($this->product->id);
                    $product->bolComCredentials()->updateExistingPivot(
                        $this->bolComCredential->id,
                        ['reference' => $response['entityId']]
                    );
                    $product->save();

                    $offer = $apiClient->get('/retailer/offers/'.$response['entityId']);

                    $bolComProductService->sendSuccessMail($this->product, $offer, $this->bolComCredential);
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
