<?php

namespace App\Jobs;

use App\Jobs\Middleware\DisconnectsIdleRedis;
use App\Models\Product;
use App\Services\DeliveryTimeService;
use App\Services\WooCommerceStockSyncService;
use Diesite\Monitor\Monitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Recomputes the delivery time of every variant after the rules on
 * Tools → Levertijden changed, and pushes the changed ones to WooCommerce
 * through the lightweight stock endpoint.
 *
 * Also writes the brand rule into the parents' levertijd_voorradig and
 * levertijd_niet_voorradig, so the next full product sync does not send the
 * shop the old texts again. Those are not pushed: the shop reads the
 * variation's own delivery time first.
 */
class ApplyDeliveryTimesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CHUNK_SIZE = 500;

    public $timeout = 3600;

    /**
     * Runs on the dedicated long-running connection/queue (Horizon
     * supervisor-long): a first run rewrites tens of thousands of variants,
     * well past the shared queue's retry_after.
     */
    public function __construct()
    {
        $this->onConnection('redis-long');
        $this->onQueue('long');
    }

    /**
     * Rewriting variants that did not change issues no Redis command, so a
     * run can sit quiet past the Redis idle timeout. {@see DisconnectsIdleRedis}
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new DisconnectsIdleRedis()];
    }

    public function handle(DeliveryTimeService $deliveryTimeService): void
    {
        $rules = $deliveryTimeService->rules();
        $syncExternal = (bool) config('delivery_times.sync_external');

        $brands = $this->applyToParents($rules);
        $changed = 0;

        Product::query()
            ->whereNotNull('parent_id')
            ->select(['id', 'sku', 'parent_id', 'values'])
            ->chunkById(self::CHUNK_SIZE, function ($variants) use ($rules, $brands, $syncExternal, &$changed) {
                $stockUpdates = [];

                foreach ($variants as $variant) {
                    if (! is_array($variant->values) || ! isset($variant->values['common'])) {
                        continue;
                    }

                    $brand = $brands[$variant->parent_id] ?? DeliveryTimeService::brandFromValues($variant->values);
                    $values = DeliveryTimeService::withDeliveryTime($variant->values, $brand, $rules);

                    if ($values === $variant->values) {
                        continue;
                    }

                    $variant->values = $values;
                    $variant->saveQuietly();

                    $stockUpdates[] = WooCommerceStockSyncService::buildStockUpdate($variant);
                    $changed++;
                }

                if ($syncExternal && $stockUpdates !== []) {
                    SyncWooCommerceStockJob::dispatch($stockUpdates);
                }
            });

        Monitor::positive('Levertijden bijgewerkt', "{$changed} varianten aangepast", '🚚');
    }

    public function failed(Throwable $exception): void
    {
        Monitor::negative('Levertijden bijwerken mislukt', Str::limit($exception->getMessage(), 120), '🚚');
    }

    /**
     * Write each brand rule into its parents and return every parent's brand.
     *
     * @param  array{showroom: ?string, brands: array<string, array{in_stock: ?string, out_of_stock: ?string}>}  $rules
     * @return array<int, ?string> Parent id => brand
     */
    private function applyToParents(array $rules): array
    {
        $brands = [];

        Product::query()
            ->whereNull('parent_id')
            ->select(['id', 'values'])
            ->chunkById(self::CHUNK_SIZE, function ($parents) use ($rules, &$brands) {
                foreach ($parents as $parent) {
                    $values = DeliveryTimeService::decodeValues($parent->values);
                    $brand = DeliveryTimeService::brandFromValues($values);
                    $brands[$parent->id] = $brand;

                    $rule = $rules['brands'][$brand] ?? null;

                    if ($rule === null || ! is_array($parent->values)) {
                        continue;
                    }

                    $values['common']['levertijd_voorradig'] = $rule['in_stock'];
                    $values['common']['levertijd_niet_voorradig'] = $rule['out_of_stock'];

                    if ($values !== $parent->values) {
                        $parent->values = $values;
                        $parent->saveQuietly();
                    }
                }
            });

        return $brands;
    }
}
