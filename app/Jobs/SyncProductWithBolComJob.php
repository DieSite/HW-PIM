<?php

namespace App\Jobs;

use App\Models\BolComCredential;
use App\Models\Product as AppProduct;
use App\Services\Bol\BolSyncStateMachine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Webkul\Product\Models\Product;

class SyncProductWithBolComJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public $backoff = 30;

    public function __construct(
        protected Product $product,
        protected BolComCredential $bolComCredential,
        protected bool $previousSyncState = false,
        protected ?string $processId = null,
        protected bool $unchecked = false,
    ) {
        $this->onQueue('bolcom');
    }

    public function handle(BolSyncStateMachine $stateMachine): void
    {
        try {
            // Always rehydrate as App\Models\Product (not the Webkul base) so
            // the bolSyncEvents() relation and bol_sync_state cast are visible.
            $product = AppProduct::find($this->product->id) ?? $this->product;

            if ($this->unchecked) {
                $product->bol_com_sync = false;
            }

            $advance = $this->processId !== null
                ? $stateMachine->advance($product, $this->bolComCredential, $this->processId)
                : $stateMachine->start($product, $this->bolComCredential, $this->previousSyncState);

            if (! $advance->isTerminal && $advance->pollProcessId !== null) {
                self::dispatch(
                    $product,
                    $this->bolComCredential,
                    $this->previousSyncState,
                    $advance->pollProcessId,
                )->delay(now()->addSeconds($advance->pollDelaySeconds));
            }
        } catch (\Throwable $e) {
            Log::error('Bol.com sync job crashed unexpectedly', [
                'product_id' => $this->product->id,
                'sku'        => $this->product->sku,
                'process_id' => $this->processId,
                'error'      => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function tags(): array
    {
        return [
            'bolcom',
            'product:'.$this->product->id,
            'credential:'.$this->bolComCredential->id,
            'process:'.($this->processId ?? 'start'),
        ];
    }

    public function backoff(): array
    {
        return [30, 60, 120, 300, 600];
    }
}
