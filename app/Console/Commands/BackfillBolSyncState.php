<?php

namespace App\Console\Commands;

use App\Enums\BolSyncEventStatus;
use App\Enums\BolSyncState;
use App\Enums\BolSyncStep;
use App\Models\BolSyncEvent;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillBolSyncState extends Command
{
    protected $signature = 'bolcom:backfill-sync-state
        {--dry-run : Show what would change without writing}
        {--chunk=500 : Number of products per chunk}';

    protected $description = 'Seed bol_sync_state for existing products based on legacy pivots and product_sync_error.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $chunk = (int) $this->option('chunk');

        $summary = ['live' => 0, 'failed' => 0, 'idle' => 0];
        $total = 0;

        Product::query()
            ->whereNull('bol_sync_state')
            ->with('bolComCredentials')
            ->orderBy('id')
            ->chunkById($chunk, function ($products) use (&$summary, &$total, $dry) {
                foreach ($products as $product) {
                    $state = $this->resolveState($product);
                    $summary[$state->value] = ($summary[$state->value] ?? 0) + 1;
                    $total++;

                    if ($dry) {
                        continue;
                    }

                    DB::transaction(function () use ($product, $state) {
                        $event = BolSyncEvent::create([
                            'product_id'            => $product->id,
                            'bol_com_credential_id' => $product->bolComCredentials->first()?->id,
                            'step'                  => BolSyncStep::Manual,
                            'status'                => $state === BolSyncState::Failed ? BolSyncEventStatus::Failed : BolSyncEventStatus::Success,
                            'message'               => 'Backfill from legacy state',
                            'customer_message'      => $this->customerMessageFor($product, $state),
                            'payload'               => [
                                'legacy_product_sync_error' => $product->additional['product_sync_error'] ?? null,
                                'pivot_references'          => $product->bolComCredentials->pluck('pivot.reference')->filter()->values()->all(),
                            ],
                        ]);

                        $product->bol_last_event_id = $event->id;
                        $product->bol_sync_state = $state->value;
                        $product->bol_sync_state_at = now();
                        $product->saveQuietly();
                    });
                }
            });

        $this->table(
            ['State', 'Count'],
            collect($summary)->map(fn ($count, $state) => [$state, $count])->values()->all(),
        );
        $this->info(sprintf('Processed %d product(s)%s.', $total, $dry ? ' (dry-run, no changes)' : ''));

        return self::SUCCESS;
    }

    private function resolveState(Product $product): BolSyncState
    {
        $hasReference = $product->bolComCredentials->contains(
            fn ($credential) => ! empty($credential->pivot->reference)
        );

        if ($hasReference) {
            return BolSyncState::Live;
        }

        if (! empty($product->additional['product_sync_error'] ?? null)) {
            return BolSyncState::Failed;
        }

        return BolSyncState::Idle;
    }

    private function customerMessageFor(Product $product, BolSyncState $state): string
    {
        return match ($state) {
            BolSyncState::Live   => 'Product staat live op Bol.com (status afgeleid uit bestaande koppeling).',
            BolSyncState::Failed => $product->additional['product_sync_error'] ?? 'Eerdere synchronisatie is mislukt.',
            default              => 'Nog geen synchronisatie geprobeerd.',
        };
    }
}
