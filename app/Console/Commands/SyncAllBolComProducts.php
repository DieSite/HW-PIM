<?php

namespace App\Console\Commands;

use App\Enums\BolSyncState;
use App\Jobs\SyncProductWithBolComJob;
use App\Models\BolComCredential;
use App\Models\Product;
use Illuminate\Console\Command;

/**
 * One-shot bulk dispatcher: queue a SyncProductWithBolComJob for every product
 * that has bol_com_sync=true (optionally narrowed by credential / state / limit).
 *
 * By default, skips products that are mid-flight (any non-terminal state) so we
 * don't trample an in-progress poll loop. Pass --force to dispatch anyway.
 */
class SyncAllBolComProducts extends Command
{
    protected $signature = 'bolcom:sync-all
        {--credential= : Only dispatch for this bol_com_credentials.id (default: all active)}
        {--state=* : Only dispatch products currently in these bol_sync_state values (e.g. idle, failed, live). Default: all terminal states.}
        {--limit= : Stop after dispatching N products}
        {--force : Also dispatch products that are mid-flight (awaiting_content_match, awaiting_offer_publish)}
        {--dry-run : Show what would be dispatched without queuing jobs}';

    protected $description = 'Queue a Bol.com sync job for every active product (idempotent — uses the state machine, so re-running is safe).';

    public function handle(): int
    {
        $credentials = $this->resolveCredentials();
        if ($credentials->isEmpty()) {
            $this->error('No active Bol.com credentials found.');

            return self::FAILURE;
        }

        $allowedStates = $this->resolveAllowedStates();
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $dry = (bool) $this->option('dry-run');

        $counts = ['dispatched' => 0, 'skipped_mid_flight' => 0, 'skipped_state_filter' => 0, 'no_credentials_attached' => 0];
        $processed = 0;

        Product::query()
            ->where('bol_com_sync', true)
            ->orderBy('id')
            ->with(['bolComCredentials' => function ($q) use ($credentials) {
                $q->whereIn('bol_com_credentials.id', $credentials->pluck('id'));
            }])
            ->chunkById(500, function ($products) use ($credentials, $allowedStates, $limit, $dry, &$counts, &$processed) {
                foreach ($products as $product) {
                    if ($limit !== null && $processed >= $limit) {
                        return false;
                    }

                    $state = $product->bol_sync_state instanceof BolSyncState
                        ? $product->bol_sync_state
                        : ($product->bol_sync_state ? BolSyncState::tryFrom($product->bol_sync_state) : BolSyncState::Idle);
                    $state ??= BolSyncState::Idle;

                    if (! in_array($state, $allowedStates, true)) {
                        $counts[$state->isTerminal() ? 'skipped_state_filter' : 'skipped_mid_flight']++;

                        continue;
                    }

                    $attached = $product->bolComCredentials;
                    if ($attached->isEmpty()) {
                        $counts['no_credentials_attached']++;

                        continue;
                    }

                    foreach ($attached as $attachedCredential) {
                        $credential = $credentials->firstWhere('id', $attachedCredential->id);
                        if (! $credential) {
                            continue;
                        }

                        if (! $dry) {
                            SyncProductWithBolComJob::dispatch(
                                $product,
                                $credential,
                                true,
                                null,
                                false,
                            );
                        }
                    }

                    $counts['dispatched']++;
                    $processed++;

                    if ($processed % 100 === 0) {
                        $this->info(sprintf('  ... %d dispatched (state=%s, sku=%s)', $processed, $state->value, $product->sku));
                    }
                }
            });

        $this->newLine();
        $this->table(
            ['Outcome', 'Count'],
            collect($counts)->map(fn ($v, $k) => [$k, $v])->values()->all(),
        );

        $this->info(sprintf(
            '%s %d product(s) across %d credential(s)%s.',
            $dry ? 'Would dispatch' : 'Dispatched',
            $counts['dispatched'],
            $credentials->count(),
            $dry ? ' (dry-run)' : '',
        ));

        if (! $dry && $counts['dispatched'] > 0) {
            $this->line('  Watch Horizon on the "bolcom" queue, or check bol_com_sync_events for per-product progress.');
        }

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, BolComCredential> */
    private function resolveCredentials(): \Illuminate\Support\Collection
    {
        $credentialOption = $this->option('credential');

        return $credentialOption
            ? BolComCredential::where('id', (int) $credentialOption)->where('is_active', true)->get()
            : BolComCredential::where('is_active', true)->get();
    }

    /** @return array<int, BolSyncState> */
    private function resolveAllowedStates(): array
    {
        $forced = (bool) $this->option('force');
        $stateOption = $this->option('state');

        if (! empty($stateOption)) {
            return collect($stateOption)
                ->map(fn (string $s) => BolSyncState::tryFrom($s))
                ->filter()
                ->values()
                ->all();
        }

        if ($forced) {
            return BolSyncState::cases();
        }

        return array_values(array_filter(BolSyncState::cases(), fn (BolSyncState $s) => $s->isTerminal()));
    }
}
