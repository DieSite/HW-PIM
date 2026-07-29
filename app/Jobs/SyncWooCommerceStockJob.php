<?php

namespace App\Jobs;

use App\Services\WooCommerceStockSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Jobs\Middleware\DisconnectsIdleRedis;

/**
 * Pushes a batch of stock-only updates to WooCommerce via the lightweight
 * diesite/v1/stock endpoint. Carries plain payload arrays (not models) to
 * keep the queued payload small.
 */
class SyncWooCommerceStockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @param  array<int, array{sku: ?string, stock_quantity: int, stock_status: string}>  $updates
     */
    public function __construct(public array $updates) {}

    /**
     * May run past the Redis idle timeout without issuing a Redis command of
     * its own, so the delete() that retires it on success would find a closed
     * socket. {@see DisconnectsIdleRedis}
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new DisconnectsIdleRedis()];
    }

    public function handle(WooCommerceStockSyncService $stockSyncService): void
    {
        $stockSyncService->pushUpdates($this->updates);
    }
}
