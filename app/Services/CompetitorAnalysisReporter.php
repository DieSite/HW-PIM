<?php

namespace App\Services;

use App\Models\CompetitorPrice;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Turns one run of the competitor analysis into a report: what our prices did,
 * which competitor drove them, and which rows look wrong enough that a human
 * should check them before the price reaches the shop.
 *
 * It reads only what the run already recorded — `product_price_history` for the
 * changes and `competitor_prices` for the current competitor snapshot — so the
 * report can be rebuilt for any window afterwards.
 */
class CompetitorAnalysisReporter
{
    /**
     * Price driven down (or up) by a competitor.
     */
    public const KIND_COMPETITOR = 'competitor';

    /**
     * Back to the adviesverkoopprijs because no competitor is cheaper — which
     * for a price that WAS lower means its competitor coverage disappeared.
     */
    public const KIND_ADVIES = 'advies';

    /**
     * A "Met onderkleed" bundle price derived from its bare sibling.
     */
    public const KIND_DERIVED = 'derived';

    /**
     * Build the full report for the given window.
     *
     * @return array{
     *     since: CarbonInterface,
     *     until: CarbonInterface,
     *     changes: array<string, mixed>,
     *     shops: list<array<string, mixed>>,
     *     coverage: array<string, int>,
     *     outliers: array<string, list<array<string, mixed>>>,
     *     outlier_total: int,
     *     thresholds: array<string, float|int>,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function build(CarbonInterface $since, ?CarbonInterface $until = null): array
    {
        $until ??= now();

        $rows = $this->changeRows($since, $until);
        $cheapest = $this->cheapestCompetitorPerSku();
        $advies = $this->adviesPrices($cheapest->keys()->all());

        $outliers = $this->outliers($rows, $cheapest, $advies);

        return [
            'since'         => $since,
            'until'         => $until,
            'changes'       => $this->summarize($rows),
            'shops'         => $this->perShop($rows),
            'coverage'      => $this->coverage($since),
            'outliers'      => $outliers,
            'outlier_total' => array_sum(array_map('count', $outliers)),
            'thresholds'    => $this->thresholds(),
            'rows'          => $rows->all(),
        ];
    }

    /**
     * Every price change in the window, flattened into plain rows.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function changeRows(CarbonInterface $since, CarbonInterface $until): Collection
    {
        return ProductPriceHistory::query()
            ->where('changed_at', '>=', $since)
            ->where('changed_at', '<=', $until)
            ->orderBy('id')
            ->get()
            ->map(function (ProductPriceHistory $history): array {
                $old = $history->old_price === null ? null : (float) $history->old_price;
                $new = (float) $history->new_price;
                $reason = (string) $history->reason;

                return [
                    'sku'              => $history->sku,
                    'product_id'       => $history->product_id,
                    'old_price'        => $old,
                    'new_price'        => $new,
                    'delta'            => $old === null ? null : $new - $old,
                    'pct'              => $old === null || $old <= 0 ? null : ($new - $old) / $old * 100,
                    'kind'             => $this->kind($reason),
                    'clamped'          => str_contains($reason, 'begrensd op adviesprijs'),
                    'manual'           => str_contains($reason, 'handmatige extra korting'),
                    'shop'             => $history->competitor_shop,
                    'competitor_price' => $history->competitor_price === null ? null : (float) $history->competitor_price,
                    'competitor_url'   => $history->competitor_url,
                    'reason'           => $reason,
                    'changed_at'       => $history->changed_at,
                ];
            });
    }

    /**
     * Classify a change by the reason CompetitorPricingService wrote for it.
     *
     * The wording is the contract between the two classes; the report test
     * feeds real buildReason() output through here so a reworded reason breaks
     * a test instead of silently emptying a column of the report.
     */
    private function kind(string $reason): string
    {
        if (str_starts_with($reason, 'Afgeleid van')) {
            return self::KIND_DERIVED;
        }

        if (str_starts_with($reason, 'Teruggezet naar adviesprijs')) {
            return self::KIND_ADVIES;
        }

        return self::KIND_COMPETITOR;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summarize(Collection $rows): array
    {
        $withPct = $rows->filter(fn (array $row): bool => $row['pct'] !== null);

        return [
            'total'       => $rows->count(),
            'products'    => $rows->pluck('sku')->unique()->count(),
            'down'        => $rows->filter(fn (array $row): bool => $row['delta'] !== null && $row['delta'] < 0)->count(),
            'up'          => $rows->filter(fn (array $row): bool => $row['delta'] !== null && $row['delta'] > 0)->count(),
            'total_delta' => (float) $rows->sum(fn (array $row): float => (float) ($row['delta'] ?? 0)),
            'avg_pct'     => $withPct->isEmpty() ? null : (float) $withPct->avg('pct'),
            'competitor'  => $rows->where('kind', self::KIND_COMPETITOR)->count(),
            'advies'      => $rows->where('kind', self::KIND_ADVIES)->count(),
            'derived'     => $rows->where('kind', self::KIND_DERIVED)->count(),
            'clamped'     => $rows->where('clamped', true)->count(),
            'manual'      => $rows->where('manual', true)->count(),
        ];
    }

    /**
     * Per competitor: how often it drove a price this run, and how far.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function perShop(Collection $rows): array
    {
        $live = CompetitorPrice::query()
            ->selectRaw('shop, COUNT(*) as total')
            ->groupBy('shop')
            ->pluck('total', 'shop');

        $driven = $rows
            ->where('kind', self::KIND_COMPETITOR)
            ->filter(fn (array $row): bool => $row['shop'] !== null)
            ->groupBy('shop');

        return $live->keys()
            ->merge($driven->keys())
            ->unique()
            ->map(function (string $shop) use ($live, $driven): array {
                /** @var Collection<int, array<string, mixed>> $shopRows */
                $shopRows = $driven->get($shop, collect());
                $withPct = $shopRows->filter(fn (array $row): bool => $row['pct'] !== null);

                return [
                    'shop'    => $shop,
                    'prices'  => (int) ($live[$shop] ?? 0),
                    'changes' => $shopRows->count(),
                    'avg_pct' => $withPct->isEmpty() ? null : (float) $withPct->avg('pct'),
                ];
            })
            ->sortByDesc('changes')
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function coverage(CarbonInterface $since): array
    {
        /** @var object{total: int, skus: int, shops: int}|null $totals */
        $totals = CompetitorPrice::query()
            ->selectRaw('COUNT(*) as total, COUNT(DISTINCT sku) as skus, COUNT(DISTINCT shop) as shops')
            ->first();

        return [
            'prices' => (int) ($totals->total ?? 0),
            'skus'   => (int) ($totals->skus ?? 0),
            'shops'  => (int) ($totals->shops ?? 0),
            'fresh'  => CompetitorPrice::query()->where('scraped_at', '>=', $since)->count(),
        ];
    }

    /**
     * The cheapest live competitor per SKU — the one that actually sets our
     * price, and therefore the only one worth auditing for a wrong coupling.
     *
     * @return Collection<string, CompetitorPrice>
     */
    private function cheapestCompetitorPerSku(): Collection
    {
        return CompetitorPrice::query()
            ->where('price', '>', 0)
            ->orderBy('sku')
            ->orderBy('price')
            ->get(['id', 'sku', 'shop', 'price', 'url', 'scraped_at'])
            ->groupBy('sku')
            ->map(fn (Collection $prices): CompetitorPrice => $prices->first());
    }

    /**
     * The adviesverkoopprijs per SKU, read straight from the JSON column so a
     * catalog of tens of thousands of variants does not have to be hydrated.
     *
     * A legacy double-encoded `values` row yields null here and is therefore
     * left out of the ratio outliers — silently skipping is the safe side: it
     * costs a warning nobody needed, not a false accusation.
     *
     * @param  list<string>  $skus
     * @return Collection<string, float>
     */
    private function adviesPrices(array $skus): Collection
    {
        $prices = collect();

        foreach (array_chunk($skus, 1000) as $chunk) {
            Product::query()
                ->whereIn('sku', $chunk)
                ->selectRaw("sku, `values`->>'$.common.adviesverkoopprijs.EUR' as advies")
                ->get()
                ->each(function ($product) use ($prices): void {
                    if (is_numeric($product->advies) && (float) $product->advies > 0) {
                        $prices[$product->sku] = (float) $product->advies;
                    }
                });
        }

        return $prices;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<string, CompetitorPrice>  $cheapest
     * @param  Collection<string, float>  $advies
     * @return array<string, list<array<string, mixed>>>
     */
    private function outliers(Collection $rows, Collection $cheapest, Collection $advies): array
    {
        $limits = $this->thresholds();

        $drops = $rows
            ->filter(fn (array $row): bool => $row['pct'] !== null && $row['pct'] <= -$limits['drop_pct'])
            ->sortBy('pct')
            ->values()
            ->all();

        $rises = $rows
            ->filter(fn (array $row): bool => $row['pct'] !== null && $row['pct'] >= $limits['rise_pct'])
            ->sortByDesc('pct')
            ->values()
            ->all();

        return [
            'drops'         => $drops,
            'rises'         => $rises,
            'not_cheapest'  => $rows->where('clamped', true)->sortBy('sku')->values()->all(),
            'lost_coverage' => $rows->where('kind', self::KIND_ADVIES)->sortBy('sku')->values()->all(),
            'suspicious'    => $this->suspiciousCouplings($cheapest, $advies, (float) $limits['competitor_ratio']),
            'stale'         => $this->stalePrices($cheapest, (int) $limits['stale_days']),
        ];
    }

    /**
     * Competitor prices so far below our adviesverkoopprijs that they are more
     * likely a different product than a bargain — the failure mode the audit
     * found: a fuzzy match couples us to another rug and drags the price down.
     *
     * @param  Collection<string, CompetitorPrice>  $cheapest
     * @param  Collection<string, float>  $advies
     * @return list<array<string, mixed>>
     */
    private function suspiciousCouplings(Collection $cheapest, Collection $advies, float $ratioPct): array
    {
        return $cheapest
            ->filter(function (CompetitorPrice $price, string $sku) use ($advies, $ratioPct): bool {
                $ceiling = $advies->get($sku);

                return $ceiling !== null && (float) $price->price < $ceiling * $ratioPct / 100;
            })
            ->map(fn (CompetitorPrice $price, string $sku): array => [
                'sku'              => $sku,
                'shop'             => $price->shop,
                'competitor_price' => (float) $price->price,
                'advies'           => $advies->get($sku),
                'ratio'            => (float) $price->price / $advies->get($sku) * 100,
                'competitor_url'   => $price->url,
                'scraped_at'       => $price->scraped_at,
            ])
            ->sortBy('ratio')
            ->values()
            ->all();
    }

    /**
     * Cheapest competitor prices the scraper has not confirmed for a while.
     * They keep setting our price regardless — the store is sticky on purpose,
     * so an unconfirmed price is not removed, only increasingly a guess.
     *
     * @param  Collection<string, CompetitorPrice>  $cheapest
     * @return list<array<string, mixed>>
     */
    private function stalePrices(Collection $cheapest, int $days): array
    {
        $cutoff = now()->subDays($days);

        return $cheapest
            ->filter(fn (CompetitorPrice $price): bool => $price->scraped_at === null || $price->scraped_at->lt($cutoff))
            ->map(fn (CompetitorPrice $price, string $sku): array => [
                'sku'              => $sku,
                'shop'             => $price->shop,
                'competitor_price' => (float) $price->price,
                'competitor_url'   => $price->url,
                'scraped_at'       => $price->scraped_at,
                'age_days'         => $price->scraped_at === null ? null : (int) $price->scraped_at->diffInDays(now()),
            ])
            ->sortByDesc('age_days')
            ->values()
            ->all();
    }

    /**
     * @return array<string, float|int>
     */
    private function thresholds(): array
    {
        $configured = (array) config('competitor_pricing.report.outliers', []);

        return [
            'drop_pct'         => (float) ($configured['drop_pct'] ?? 15),
            'rise_pct'         => (float) ($configured['rise_pct'] ?? 15),
            'competitor_ratio' => (float) ($configured['competitor_ratio'] ?? 60),
            'stale_days'       => (int) ($configured['stale_days'] ?? 14),
            'max_rows'         => (int) ($configured['max_rows'] ?? 25),
        ];
    }

    /**
     * Every change in the window as CSV, so the mail body can stay a summary
     * while the full run is still reviewable in a spreadsheet.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'SKU', 'Oude prijs', 'Nieuwe prijs', 'Verschil', 'Verschil %',
            'Type', 'Concurrent', 'Concurrentprijs', 'Reden', 'URL', 'Gewijzigd op',
        ], ';');

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['sku'],
                $this->number($row['old_price']),
                $this->number($row['new_price']),
                $this->number($row['delta']),
                $row['pct'] === null ? '' : number_format($row['pct'], 1, ',', ''),
                $this->kindLabel((string) $row['kind']),
                $row['shop'] ?? '',
                $this->number($row['competitor_price']),
                $row['reason'],
                $row['competitor_url'] ?? '',
                $row['changed_at']?->format('d-m-Y H:i') ?? '',
            ], ';');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::KIND_ADVIES  => 'Terug naar adviesprijs',
            self::KIND_DERIVED => 'Afgeleid (met onderkleed)',
            default            => 'Concurrent',
        };
    }

    private function number(?float $value): string
    {
        return $value === null ? '' : number_format($value, 2, ',', '');
    }
}
