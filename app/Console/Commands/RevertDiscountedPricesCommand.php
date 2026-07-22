<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Services\CompetitorPricingService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RevertDiscountedPricesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'pricing:revert-discounted-prices
                            {--all : Revert every variant priced below advies, not only system-applied discounts}
                            {--dry-run : Only report what would change, change nothing}
                            {--no-sync : Skip the WooCommerce/Bol.com sync dispatch}
                            {--disable-analysis : Also switch the nightly competitor analysis off}
                            {--force : Do not ask for confirmation}';

    /**
     * @var string
     */
    protected $description = 'Safety switch: set prijs back to adviesverkoopprijs for discounted products (and optionally disable the competitor analysis).';

    public function handle(CompetitorPricingService $pricing): int
    {
        $candidates = $this->discountedVariants();

        if (! $this->option('all')) {
            $candidates = $this->onlySystemApplied($candidates);
        }

        if ($candidates->isEmpty()) {
            $this->info('No discounted prices to revert.');
            $this->maybeDisableAnalysis();

            return self::SUCCESS;
        }

        $totalDelta = $candidates->sum(fn (array $c): float => $c['advies'] - $c['prijs']);

        $this->info(sprintf(
            '%d variants priced below advies (%s mode), total delta € %s.',
            $candidates->count(),
            $this->option('all') ? 'ALL discounts' : 'system-applied discounts only',
            number_format($totalDelta, 2, ',', '.'),
        ));

        $this->table(
            ['SKU', 'Prijs', 'Advies'],
            $candidates->take(15)->map(fn (array $c): array => [$c['variant']->sku, $c['prijs'], $c['advies']])->all(),
        );

        if ($this->option('dry-run')) {
            $this->info('Dry-run: nothing changed.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Revert {$candidates->count()} prices back to adviesverkoopprijs?")) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $reverted = [];

        foreach ($candidates as $candidate) {
            $variant = $candidate['variant'];

            $values = $variant->values;
            $values['common']['prijs']['EUR'] = (string) (int) $candidate['advies'];
            $variant->values = $values;
            $variant->saveQuietly();

            ProductPriceHistory::create([
                'product_id' => $variant->id,
                'sku'        => $variant->sku,
                'old_price'  => $candidate['prijs'],
                'new_price'  => $candidate['advies'],
                'reason'     => 'Handmatig teruggezet naar adviesprijs via pricing:revert-discounted-prices.',
                'changed_at' => now(),
            ]);

            $reverted[] = $variant;
        }

        $this->info(count($reverted).' prices reverted to adviesverkoopprijs.');

        if ($this->option('no-sync')) {
            $this->warn('Sync skipped (--no-sync): WooCommerce/Bol.com still show the old prices.');
        } else {
            $pricing->dispatchSync($reverted);
            $this->info('WooCommerce/Bol.com syncs dispatched.');
        }

        $this->maybeDisableAnalysis();

        if (! $this->option('disable-analysis') && $this->analysisEnabled()) {
            $this->warn('Let op: de nachtelijke concurrentie-analyse staat nog AAN en past de kortingen morgen opnieuw toe. Gebruik --disable-analysis of zet hem uit onder Configuratie → Prijsstelling.');
        }

        return self::SUCCESS;
    }

    /**
     * Every variant whose prijs is below its adviesverkoopprijs.
     *
     * @return Collection<int, array{variant: Product, prijs: float, advies: float}>
     */
    private function discountedVariants(): Collection
    {
        $candidates = collect();

        Product::whereNotNull('parent_id')->chunkById(500, function (Collection $variants) use (&$candidates): void {
            foreach ($variants as $variant) {
                $common = $variant->values['common'] ?? [];
                $prijs = $this->toFloat($common['prijs']['EUR'] ?? null);
                $advies = $this->toFloat($common['adviesverkoopprijs']['EUR'] ?? null);

                if ($prijs === null || $advies === null || $advies <= 0) {
                    continue;
                }

                if ($prijs < $advies - 0.005) {
                    $candidates->push(['variant' => $variant, 'prijs' => $prijs, 'advies' => $advies]);
                }
            }
        });

        return $candidates;
    }

    /**
     * Keep only variants whose current prijs was set by the pricing system:
     * their latest price-history row matches the current prijs. Manual admin
     * discounts (no history, or edited after the last system change) are left
     * alone unless --all is passed.
     *
     * @param  Collection<int, array{variant: Product, prijs: float, advies: float}>  $candidates
     * @return Collection<int, array{variant: Product, prijs: float, advies: float}>
     */
    private function onlySystemApplied(Collection $candidates): Collection
    {
        $latestBySku = ProductPriceHistory::query()
            ->whereIn('product_id', $candidates->pluck('variant.id')->all())
            ->orderBy('id')
            ->get(['product_id', 'new_price'])
            ->groupBy('product_id')
            ->map(fn (Collection $rows) => $rows->last());

        return $candidates->filter(function (array $c) use ($latestBySku): bool {
            $latest = $latestBySku->get($c['variant']->id);

            return $latest !== null && abs((float) $latest->new_price - $c['prijs']) < 0.005;
        })->values();
    }

    private function maybeDisableAnalysis(): void
    {
        if (! $this->option('disable-analysis')) {
            return;
        }

        DB::table('core_config')->updateOrInsert(
            ['code' => 'general.pricing.settings.enabled'],
            ['value' => '0', 'updated_at' => now(), 'created_at' => now()],
        );

        $this->info('Nachtelijke concurrentie-analyse uitgeschakeld (Configuratie → Prijsstelling).');
    }

    private function analysisEnabled(): bool
    {
        $configured = core()->getConfigData('general.pricing.settings.enabled');

        return $configured === null || (bool) $configured;
    }

    private function toFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
