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
 *
 * Besides the changes it runs two sets of checks. The PIPELINE checks ask
 * whether the analysis itself did its work (did every shop deliver, did the
 * scrape refresh). The PRICE checks are indirect: nothing about them is proof,
 * they are the circumstantial patterns that in practice accompany a wrong
 * price — a lone competitor disagreeing with all the others, one page priced
 * against several of our sizes, a rug whose price per m² falls outside its own
 * model family. A run can be green on every pipeline check and still have put
 * a wrong price in the shop; that is what these are for.
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
     * A check that found nothing to worry about.
     */
    public const STATUS_OK = 'ok';

    /**
     * Something is off; the prices are probably usable but worth a look.
     */
    public const STATUS_WARN = 'warn';

    /**
     * Something is broken: prices in the shop may be wrong right now.
     */
    public const STATUS_ALERT = 'alert';

    /**
     * Checks about the analysis run itself.
     */
    public const GROUP_PIPELINE = 'pipeline';

    /**
     * Checks that indirectly suggest a price is not correct.
     */
    public const GROUP_PRICES = 'prices';

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
     *     checks: list<array<string, mixed>>,
     *     alerts: int,
     *     warnings: int,
     *     flagged: int,
     *     thresholds: array<string, float|int>,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function build(CarbonInterface $since, ?CarbonInterface $until = null): array
    {
        $until ??= now();

        $rows = $this->changeRows($since, $until);
        $competitors = $this->competitorPrices();
        $cheapest = $this->cheapestPerSku($competitors);

        $products = $this->productPrices(array_values(array_unique(array_merge(
            $cheapest->keys()->all(),
            $rows->pluck('sku')->unique()->all(),
        ))));

        $families = $this->families($rows, $products);

        $checks = array_merge(
            $this->pipelineChecks($rows, $competitors, $cheapest, $products, $since),
            $this->priceChecks($rows, $competitors, $cheapest, $products, $families),
        );

        $outliers = $this->outliers($rows, $cheapest, $products);

        return [
            'since'         => $since,
            'until'         => $until,
            'changes'       => $this->summarize($rows),
            'shops'         => $this->perShop($rows, $competitors, $products, $since),
            'coverage'      => $this->coverage($competitors, $since),
            'outliers'      => $outliers,
            'outlier_total' => array_sum(array_map('count', $outliers)),
            'checks'        => $checks,
            'alerts'        => count(array_filter($checks, fn (array $c): bool => $c['status'] === self::STATUS_ALERT)),
            'warnings'      => count(array_filter($checks, fn (array $c): bool => $c['status'] === self::STATUS_WARN)),
            'flagged'       => array_sum(array_map(fn (array $c): int => $c['count'], $checks)),
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
     * Per competitor: how much of it was refreshed, how often it drove a price
     * this run, and where its prices sit relative to our adviesprijs.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<int, CompetitorPrice>  $competitors
     * @param  Collection<string, array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function perShop(Collection $rows, Collection $competitors, Collection $products, CarbonInterface $since): array
    {
        $byShop = $competitors->groupBy('shop');

        $driven = $rows
            ->where('kind', self::KIND_COMPETITOR)
            ->filter(fn (array $row): bool => $row['shop'] !== null)
            ->groupBy('shop');

        return $byShop->keys()
            ->merge($driven->keys())
            ->unique()
            ->map(function (string $shop) use ($byShop, $driven, $products, $since): array {
                /** @var Collection<int, CompetitorPrice> $prices */
                $prices = $byShop->get($shop, collect());

                /** @var Collection<int, array<string, mixed>> $shopRows */
                $shopRows = $driven->get($shop, collect());
                $withPct = $shopRows->filter(fn (array $row): bool => $row['pct'] !== null);

                return [
                    'shop'         => $shop,
                    'prices'       => $prices->count(),
                    'fresh'        => $prices->filter(fn (CompetitorPrice $p): bool => $this->isFresh($p, $since))->count(),
                    'changes'      => $shopRows->count(),
                    'avg_pct'      => $withPct->isEmpty() ? null : (float) $withPct->avg('pct'),
                    'median_ratio' => $this->medianRatio($prices, $products),
                ];
            })
            ->sortByDesc('changes')
            ->values()
            ->all();
    }

    /**
     * The median of competitor price ÷ adviesverkoopprijs for one shop. The
     * audit put healthy couplings at 75–110%; a whole shop sitting far outside
     * that band is not having a sale, it is matching the wrong products.
     *
     * @param  Collection<int, CompetitorPrice>  $prices
     * @param  Collection<string, array<string, mixed>>  $products
     */
    private function medianRatio(Collection $prices, Collection $products): ?float
    {
        $ratios = $prices
            ->map(function (CompetitorPrice $price) use ($products): ?float {
                $advies = $products->get($price->sku)['advies'] ?? null;

                return $advies === null || $advies <= 0 ? null : (float) $price->price / $advies * 100;
            })
            ->filter()
            ->values();

        return $ratios->isEmpty() ? null : (float) $ratios->median();
    }

    /**
     * @param  Collection<int, CompetitorPrice>  $competitors
     * @return array<string, int>
     */
    private function coverage(Collection $competitors, CarbonInterface $since): array
    {
        return [
            'prices' => $competitors->count(),
            'skus'   => $competitors->pluck('sku')->unique()->count(),
            'shops'  => $competitors->pluck('shop')->unique()->count(),
            'fresh'  => $competitors->filter(fn (CompetitorPrice $p): bool => $this->isFresh($p, $since))->count(),
        ];
    }

    /** Whether the scraper confirmed this price during the reported run. */
    private function isFresh(CompetitorPrice $price, CarbonInterface $since): bool
    {
        return $price->scraped_at !== null && $price->scraped_at->gte($since);
    }

    /**
     * Every live competitor price, loaded once and reused by the coverage,
     * per-shop and check sections, which each need a different cut of it.
     *
     * @return Collection<int, CompetitorPrice>
     */
    private function competitorPrices(): Collection
    {
        return CompetitorPrice::query()
            ->where('price', '>', 0)
            ->orderBy('sku')
            ->orderBy('price')
            ->get(['id', 'sku', 'shop', 'price', 'url', 'scraped_at']);
    }

    /**
     * The cheapest live competitor per SKU — the one that actually sets our
     * price, and therefore the only one worth auditing for a wrong coupling.
     *
     * @param  Collection<int, CompetitorPrice>  $competitors
     * @return Collection<string, CompetitorPrice>
     */
    private function cheapestPerSku(Collection $competitors): Collection
    {
        return $competitors
            ->groupBy('sku')
            ->map(fn (Collection $prices): CompetitorPrice => $prices->first());
    }

    /**
     * Prices and size per SKU, read straight from the JSON column so a catalog
     * of tens of thousands of variants does not have to be hydrated.
     *
     * A legacy double-encoded `values` row yields nulls here and is therefore
     * left out of the ratio checks — silently skipping is the safe side: it
     * costs a warning nobody needed, not a false accusation. A SKU missing
     * from the result has no product at all, which is its own signal.
     *
     * @param  list<string>  $skus
     * @return Collection<string, array{advies: ?float, prijs: ?float, maat: ?string, parent_id: ?int, onderkleed: ?string, area: ?float}>
     */
    private function productPrices(array $skus): Collection
    {
        $prices = collect();

        foreach (array_chunk($skus, 1000) as $chunk) {
            Product::query()
                ->whereIn('sku', $chunk)
                ->selectRaw(
                    'sku, parent_id, '
                    ."`values`->>'$.common.adviesverkoopprijs.EUR' as advies, "
                    ."`values`->>'$.common.prijs.EUR' as prijs, "
                    ."`values`->>'$.common.maat' as maat, "
                    ."`values`->>'$.common.onderkleed' as onderkleed"
                )
                ->get()
                ->each(function ($product) use ($prices): void {
                    $maat = is_string($product->maat) && $product->maat !== 'null' ? $product->maat : null;

                    $prices[$product->sku] = [
                        'advies'     => $this->positive($product->advies),
                        'prijs'      => $this->positive($product->prijs),
                        'maat'       => $maat,
                        'parent_id'  => $product->parent_id,
                        'onderkleed' => is_string($product->onderkleed) && $product->onderkleed !== 'null' ? $product->onderkleed : null,
                        'area'       => $maat === null ? null : $this->area($maat),
                    ];
                });
        }

        return $prices;
    }

    private function positive(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    /**
     * Surface of a rug in m², parsed from the `maat` attribute.
     *
     * Sizes are written as "200 cm x 300 cm", "Rond 200 cm" or "Ovaal 200 cm x
     * 290 cm"; a round size carries one measure and an oval covers π/4 of its
     * bounding box. Custom sizes ("Maatwerk") have no fixed surface and return
     * null, which keeps them out of every per-m² comparison.
     */
    public function area(string $maat): ?float
    {
        $value = mb_strtolower($maat);

        if (str_contains($value, 'maatwerk')) {
            return null;
        }

        $round = str_contains($value, 'rond') || str_contains($value, 'ø') || str_contains($value, 'cirkel');
        $oval = str_contains($value, 'ovaal') || str_contains($value, 'ovale') || str_contains($value, 'oval') || str_contains($value, 'ellips');

        if (preg_match('/(\d{2,4})\s*(?:cm)?\s*[x×]\s*(\d{2,4})/u', $value, $matches) === 1) {
            $surface = (float) $matches[1] * (float) $matches[2];

            return ($oval ? $surface * M_PI / 4 : $surface) / 10000;
        }

        if (($round || $oval) && preg_match('/(\d{2,4})/u', $value, $matches) === 1) {
            $diameter = (float) $matches[1];

            return M_PI * ($diameter / 2) ** 2 / 10000;
        }

        return null;
    }

    /**
     * The sized variants of every model family touched this run, so a price
     * can be judged against its own siblings rather than against the catalog.
     *
     * Bounded to the families that changed: a family nobody touched tonight
     * cannot have been broken by tonight's run.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<string, array<string, mixed>>  $products
     * @return Collection<int, Collection<int, array<string, mixed>>>
     */
    private function families(Collection $rows, Collection $products): Collection
    {
        $parentIds = $rows
            ->pluck('sku')
            ->unique()
            ->map(fn (string $sku): ?int => $products->get($sku)['parent_id'] ?? null)
            ->filter()
            ->unique()
            ->values();

        if ($parentIds->isEmpty()) {
            return collect();
        }

        $variants = collect();

        foreach ($parentIds->chunk(500) as $chunk) {
            Product::query()
                ->whereIn('parent_id', $chunk->all())
                ->selectRaw(
                    'sku, parent_id, '
                    ."`values`->>'$.common.prijs.EUR' as prijs, "
                    ."`values`->>'$.common.maat' as maat, "
                    ."`values`->>'$.common.onderkleed' as onderkleed"
                )
                ->get()
                ->each(function ($product) use ($variants): void {
                    $maat = is_string($product->maat) && $product->maat !== 'null' ? $product->maat : null;

                    $variants->push([
                        'sku'        => $product->sku,
                        'parent_id'  => (int) $product->parent_id,
                        'prijs'      => $this->positive($product->prijs),
                        'maat'       => $maat,
                        'onderkleed' => is_string($product->onderkleed) && $product->onderkleed !== 'null' ? $product->onderkleed : null,
                        'area'       => $maat === null ? null : $this->area($maat),
                    ]);
                });
        }

        return $variants->groupBy('parent_id');
    }

    /**
     * Checks on the run itself: did every competitor deliver, and did the
     * result stay within the shape a normal night has.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<int, CompetitorPrice>  $competitors
     * @param  Collection<string, CompetitorPrice>  $cheapest
     * @param  Collection<string, array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function pipelineChecks(
        Collection $rows,
        Collection $competitors,
        Collection $cheapest,
        Collection $products,
        CarbonInterface $since,
    ): array {
        $limits = $this->thresholds();
        $checks = [];

        $total = $competitors->count();
        $fresh = $competitors->filter(fn (CompetitorPrice $p): bool => $this->isFresh($p, $since))->count();
        $refreshPct = $total === 0 ? 0.0 : $fresh / $total * 100;

        $checks[] = $this->check(
            group: self::GROUP_PIPELINE,
            key: 'refresh_rate',
            label: 'Verversingsgraad van de scrape',
            status: $total === 0 || $refreshPct < $limits['min_refresh_pct'] ? self::STATUS_ALERT : self::STATUS_OK,
            value: $total === 0 ? 'geen concurrentprijzen' : $this->pct($refreshPct).' ('.$fresh.' van '.$total.')',
            detail: 'Prijzen die deze run niet opnieuw zijn opgehaald blijven staan en blijven onze prijs bepalen. Ver onder de '
                .$this->pct((float) $limits['min_refresh_pct']).' betekent meestal dat de scraper vroegtijdig is gestopt.',
            items: [],
        );

        $byShop = $competitors->groupBy('shop');

        $silent = $byShop
            ->filter(fn (Collection $prices): bool => $prices->filter(fn (CompetitorPrice $p): bool => $this->isFresh($p, $since))->isEmpty())
            ->map(fn (Collection $prices, string $shop): string => $shop.' — 0 van '.$prices->count().' ververst')
            ->values()
            ->all();

        $checks[] = $this->check(
            group: self::GROUP_PIPELINE,
            key: 'silent_shops',
            label: 'Concurrenten die deze run niets leverden',
            status: $silent === [] ? self::STATUS_OK : self::STATUS_ALERT,
            value: count($silent).' van '.$this->plural($byShop->count(), 'winkel', 'winkels'),
            detail: 'Deze winkels staan wél in de database maar leverden geen enkele verse prijs. Dat wijst op een kapotte scraper-spec of een gewijzigde website — hun oude prijzen bepalen intussen gewoon onze prijs.',
            items: $silent,
        );

        $partial = $byShop
            ->map(function (Collection $prices, string $shop) use ($since, $limits): ?string {
                $shopFresh = $prices->filter(fn (CompetitorPrice $p): bool => $this->isFresh($p, $since))->count();

                if ($shopFresh === 0 || $shopFresh >= $prices->count() * $limits['shop_partial_pct'] / 100) {
                    return null;
                }

                return $shop.' — '.$shopFresh.' van '.$prices->count().' ververst ('.$this->pct($shopFresh / $prices->count() * 100).')';
            })
            ->filter()
            ->values()
            ->all();

        $checks[] = $this->check(
            group: self::GROUP_PIPELINE,
            key: 'partial_shops',
            label: 'Concurrenten die maar deels geleverd hebben',
            status: $partial === [] ? self::STATUS_OK : self::STATUS_WARN,
            value: $this->plural(count($partial), 'winkel', 'winkels').' onder '.$this->pct((float) $limits['shop_partial_pct']),
            detail: 'Een gedeeltelijke scrape geeft een scheef beeld: de opgehaalde helft bepaalt de prijs, de niet-opgehaalde helft blijft op oude waarden staan.',
            items: $partial,
        );

        $drifted = collect($this->perShop($rows, $competitors, $products, $since))
            ->filter(fn (array $shop): bool => $shop['median_ratio'] !== null
                && ($shop['median_ratio'] < $limits['shop_ratio_low'] || $shop['median_ratio'] > $limits['shop_ratio_high']))
            ->map(fn (array $shop): string => $shop['shop'].' — mediaan '.$this->pct($shop['median_ratio']).' van de adviesprijs ('.$shop['prices'].' prijzen)')
            ->values()
            ->all();

        $checks[] = $this->check(
            group: self::GROUP_PIPELINE,
            key: 'shop_ratio',
            label: 'Winkels waarvan het hele prijsniveau afwijkt',
            status: $drifted === [] ? self::STATUS_OK : self::STATUS_WARN,
            value: $this->plural(count($drifted), 'winkel', 'winkels').' buiten '.$this->pct((float) $limits['shop_ratio_low']).'–'.$this->pct((float) $limits['shop_ratio_high']),
            detail: 'Een gezonde koppeling zit rond 75–110% van onze adviesprijs. Wijkt de mediaan van een hele winkel af, dan is het geen actie maar een systematisch verkeerde koppeling (of een verkeerd gelezen valuta/eenheid).',
            items: $drifted,
        );

        $orphans = $cheapest
            ->keys()
            ->reject(fn (string $sku): bool => $products->has($sku))
            ->values();

        $checks[] = $this->check(
            group: self::GROUP_PIPELINE,
            key: 'orphan_skus',
            label: 'Concurrentprijzen zonder product in de PIM',
            status: $orphans->isEmpty() ? self::STATUS_OK : self::STATUS_WARN,
            value: $this->plural($orphans->count(), 'SKU', 'SKU\'s'),
            detail: 'Deze SKU\'s bestaan niet (meer) in de PIM terwijl er wel concurrentprijzen voor bewaard blijven. Ze doen niets, maar ze zijn ook nooit opgeruimd — een teken dat de catalogus en de scraper uit de pas lopen.',
            items: $orphans->take((int) $limits['max_items'])->all(),
        );

        $variants = Product::query()->whereNotNull('parent_id')->count();
        $changedShare = $variants === 0 ? 0.0 : $rows->pluck('sku')->unique()->count() / $variants * 100;

        $checks[] = $this->check(
            group: self::GROUP_PIPELINE,
            key: 'mass_change',
            label: 'Omvang van de wijziging',
            status: $changedShare > $limits['mass_change_pct'] ? self::STATUS_WARN : self::STATUS_OK,
            value: $this->pct($changedShare).' van de varianten',
            detail: 'Concurrentiedrift raakt normaal een klein deel van de catalogus. Verandert er in één nacht een groot deel, dan is er meestal iets structureels gewijzigd (kortingspercentage, adviesprijzen, een import) in plaats van de markt.',
            items: [],
        );

        $flapping = ProductPriceHistory::query()
            ->where('changed_at', '>=', now()->subDays(7))
            ->selectRaw('sku, COUNT(DISTINCT DATE(changed_at)) as dagen')
            ->groupBy('sku')
            ->having('dagen', '>=', $limits['flapping_days'])
            ->orderByDesc('dagen')
            ->limit(200)
            ->pluck('dagen', 'sku');

        $checks[] = $this->check(
            group: self::GROUP_PIPELINE,
            key: 'flapping',
            label: 'Prijzen die blijven heen en weer springen',
            status: $flapping->isEmpty() ? self::STATUS_OK : self::STATUS_WARN,
            value: $this->plural($flapping->count(), 'SKU', 'SKU\'s').' op '.$limits['flapping_days'].'+ van de laatste 7 dagen',
            detail: 'Een prijs die vrijwel elke nacht wijzigt volgt geen markt maar een instabiele match: de concurrent wordt de ene nacht wel en de andere nacht niet gevonden. Klanten zien de prijs dan dagelijks springen.',
            items: $flapping->take((int) $limits['max_items'])
                ->map(fn (int $days, string $sku): string => $sku.' — '.$days.' van de 7 dagen gewijzigd')
                ->values()
                ->all(),
        );

        return $checks;
    }

    /**
     * Indirect tells that a price is not correct. None of these prove an error;
     * they are the patterns that in practice accompany one.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<int, CompetitorPrice>  $competitors
     * @param  Collection<string, CompetitorPrice>  $cheapest
     * @param  Collection<string, array<string, mixed>>  $products
     * @param  Collection<int, Collection<int, array<string, mixed>>>  $families
     * @return list<array<string, mixed>>
     */
    private function priceChecks(
        Collection $rows,
        Collection $competitors,
        Collection $cheapest,
        Collection $products,
        Collection $families,
    ): array {
        $limits = $this->thresholds();
        $checks = [];

        $violations = $cheapest
            ->keys()
            ->merge($rows->pluck('sku'))
            ->unique()
            ->map(fn (string $sku): ?string => $this->ceilingViolation($sku, $products))
            ->filter()
            ->values();

        $checks[] = $this->check(
            group: self::GROUP_PRICES,
            key: 'above_ceiling',
            label: 'Verkoopprijs boven de adviesprijs',
            status: $violations->isEmpty() ? self::STATUS_OK : self::STATUS_ALERT,
            value: $this->plural($violations->count(), 'variant', 'varianten'),
            detail: 'De adviesprijs is een hard plafond in de prijsberekening, dus dit kan alleen als iets buiten de concurrentielogica om de prijs heeft geschreven (een import, een handmatige bewerking) of als de adviesprijs achteraf is verlaagd.',
            items: $violations->take((int) $limits['max_items'])->all(),
        );

        $dissenters = $this->loneDissenters($competitors, (float) $limits['dissent_pct']);

        $checks[] = $this->check(
            group: self::GROUP_PRICES,
            key: 'lone_dissenter',
            label: 'Eén concurrent wijkt sterk af van de rest',
            status: $dissenters === [] ? self::STATUS_OK : self::STATUS_WARN,
            value: $this->plural(count($dissenters), 'variant', 'varianten'),
            detail: 'Bij deze kleden is de goedkoopste concurrent minstens '.$this->pct((float) $limits['dissent_pct'])
                .' goedkoper dan de op één na goedkoopste. Als meerdere winkels het eens zijn en één wijkt sterk af, is die ene meestal een ander product — en juist die ene bepaalt onze prijs.',
            items: array_slice($dissenters, 0, (int) $limits['max_items']),
        );

        $shared = $this->sharedPricePages($competitors, $products);

        $checks[] = $this->check(
            group: self::GROUP_PRICES,
            key: 'shared_price',
            label: 'Eén concurrentpagina voor meerdere maten',
            status: $shared === [] ? self::STATUS_OK : self::STATUS_WARN,
            value: $this->plural(count($shared), 'pagina', 'pagina\'s'),
            detail: 'Dezelfde pagina levert exact dezelfde prijs voor verschillende maten van ons. Een groter kleed kost bij een concurrent nooit hetzelfde als een kleiner kleed, dus hier is de maat niet meegenomen bij het koppelen.',
            items: array_slice($shared, 0, (int) $limits['max_items']),
        );

        $perSquare = $this->pricePerSquareMetreOutliers($rows, $families, (float) $limits['psqm_deviation_pct']);

        $checks[] = $this->check(
            group: self::GROUP_PRICES,
            key: 'price_per_m2',
            label: 'Prijs per m² wijkt af binnen hetzelfde model',
            status: $perSquare === [] ? self::STATUS_OK : self::STATUS_WARN,
            value: $this->plural(count($perSquare), 'variant', 'varianten'),
            detail: 'Binnen één model kost elke maat ongeveer evenveel per m². Wijkt één maat sterk af van zijn eigen broertjes, dan klopt de prijs van díe maat waarschijnlijk niet — dit vindt fouten waar geen enkele concurrent iets over zegt.',
            items: array_slice($perSquare, 0, (int) $limits['max_items']),
        );

        $bundles = $this->bundlesNotAboveBase($families);

        $checks[] = $this->check(
            group: self::GROUP_PRICES,
            key: 'bundle_price',
            label: 'Met onderkleed niet duurder dan zonder',
            status: $bundles === [] ? self::STATUS_OK : self::STATUS_WARN,
            value: $this->plural(count($bundles), 'maat', 'maten'),
            detail: 'De variant mét onderkleed is het kale kleed plus de onderkleedtoeslag en hoort dus altijd duurder te zijn. Staat hij gelijk of lager, dan is de afgeleide prijs niet bijgewerkt of loopt de adviesprijs van de bundel niet gelijk met die van het kale kleed.',
            items: array_slice($bundles, 0, (int) $limits['max_items']),
        );

        $unmanaged = $cheapest
            ->keys()
            ->filter(fn (string $sku): bool => $products->has($sku) && $products->get($sku)['advies'] === null)
            ->values();

        $checks[] = $this->check(
            group: self::GROUP_PRICES,
            key: 'missing_advies',
            label: 'Concurrentprijs zonder adviesverkoopprijs',
            status: $unmanaged->isEmpty() ? self::STATUS_OK : self::STATUS_WARN,
            value: $this->plural($unmanaged->count(), 'variant', 'varianten'),
            detail: 'Zonder adviesprijs slaat de prijsberekening een variant over: er is geen plafond en geen bodem, dus de prijs in de winkel wordt door niets bewaakt terwijl er wél concurrentprijzen voor binnenkomen.',
            items: $unmanaged->take((int) $limits['max_items'])->all(),
        );

        return $checks;
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $products
     */
    private function ceilingViolation(string $sku, Collection $products): ?string
    {
        $product = $products->get($sku);

        if ($product === null || $product['advies'] === null || $product['prijs'] === null) {
            return null;
        }

        if ($product['prijs'] <= $product['advies'] + 0.5) {
            return null;
        }

        return $sku.' — prijs '.$this->euro($product['prijs']).' boven advies '.$this->euro($product['advies']);
    }

    /**
     * SKUs where the cheapest competitor sits far below the next cheapest.
     *
     * @param  Collection<int, CompetitorPrice>  $competitors
     * @return list<string>
     */
    private function loneDissenters(Collection $competitors, float $gapPct): array
    {
        return $competitors
            ->groupBy('sku')
            ->map(function (Collection $prices, string $sku) use ($gapPct): ?array {
                if ($prices->count() < 2) {
                    return null;
                }

                $sorted = $prices->sortBy('price')->values();
                $lowest = (float) $sorted[0]->price;
                $second = (float) $sorted[1]->price;

                if ($lowest <= 0 || $second <= 0) {
                    return null;
                }

                $gap = ($second - $lowest) / $second * 100;

                return $gap < $gapPct ? null : [
                    'gap'  => $gap,
                    'text' => $sku.' — '.$sorted[0]->shop.' '.$this->euro($lowest).' tegenover '
                        .$sorted[1]->shop.' '.$this->euro($second).' ('.$this->pct($gap).' lager)',
                ];
            })
            ->filter()
            ->sortByDesc('gap')
            ->pluck('text')
            ->values()
            ->all();
    }

    /**
     * Competitor pages that returned the exact same price for more than one of
     * our sizes — a size-blind coupling.
     *
     * @param  Collection<int, CompetitorPrice>  $competitors
     * @param  Collection<string, array<string, mixed>>  $products
     * @return list<string>
     */
    private function sharedPricePages(Collection $competitors, Collection $products): array
    {
        return $competitors
            ->filter(fn (CompetitorPrice $price): bool => $price->url !== null && $price->url !== '')
            ->groupBy(fn (CompetitorPrice $price): string => $price->shop.'|'.$price->url.'|'.number_format((float) $price->price, 2))
            ->map(function (Collection $prices) use ($products): ?string {
                if ($prices->count() < 2) {
                    return null;
                }

                $sizes = $prices
                    ->map(fn (CompetitorPrice $price): ?string => $products->get($price->sku)['maat'] ?? null)
                    ->filter()
                    ->unique()
                    ->values();

                if ($sizes->count() < 2) {
                    return null;
                }

                /** @var CompetitorPrice $first */
                $first = $prices->first();

                return $first->shop.' — '.$this->euro((float) $first->price).' voor '.$sizes->count()
                    .' maten ('.$sizes->take(3)->implode(', ').') · '.$first->url;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Variants whose price per m² falls outside the band of their own model
     * family. Only families with at least three comparable sizes are judged,
     * because a median over two rugs says nothing.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<int, Collection<int, array<string, mixed>>>  $families
     * @return list<string>
     */
    private function pricePerSquareMetreOutliers(Collection $rows, Collection $families, float $deviationPct): array
    {
        $changed = $rows->pluck('sku')->unique()->flip();
        $found = [];

        foreach ($families as $variants) {
            $comparable = $variants
                ->filter(fn (array $variant): bool => $variant['area'] !== null
                    && $variant['area'] > 0
                    && $variant['prijs'] !== null
                    && $variant['onderkleed'] !== 'Met onderkleed')
                ->map(fn (array $variant): array => $variant + ['per_m2' => $variant['prijs'] / $variant['area']])
                ->values();

            if ($comparable->count() < 3) {
                continue;
            }

            $median = (float) $comparable->pluck('per_m2')->median();

            if ($median <= 0) {
                continue;
            }

            foreach ($comparable as $variant) {
                $deviation = ($variant['per_m2'] - $median) / $median * 100;

                // Alleen wat deze run is aangeraakt: een oude scheve prijs is
                // geen nieuw signaal, en anders staat elke nacht dezelfde lijst
                // in de mail tot iemand hem opruimt.
                if (abs($deviation) < $deviationPct || ! $changed->has($variant['sku'])) {
                    continue;
                }

                $found[] = [
                    'deviation' => abs($deviation),
                    'text'      => $variant['sku'].' ('.($variant['maat'] ?? 'onbekende maat').') — '
                        .$this->euro($variant['per_m2']).'/m² tegenover '.$this->euro($median).'/m² in dit model ('
                        .($deviation > 0 ? '+' : '').number_format($deviation, 0, ',', '.').'%)',
                ];
            }
        }

        usort($found, fn (array $a, array $b): int => $b['deviation'] <=> $a['deviation']);

        return array_column($found, 'text');
    }

    /**
     * Sizes where the "Met onderkleed" bundle is not priced above its bare
     * sibling, which the underlay surcharge guarantees it should be.
     *
     * @param  Collection<int, Collection<int, array<string, mixed>>>  $families
     * @return list<string>
     */
    private function bundlesNotAboveBase(Collection $families): array
    {
        $found = [];

        foreach ($families as $variants) {
            foreach ($variants->groupBy('maat') as $maat => $sized) {
                $base = $sized->firstWhere('onderkleed', 'Zonder onderkleed');
                $bundle = $sized->firstWhere('onderkleed', 'Met onderkleed');

                if ($base === null || $bundle === null || $base['prijs'] === null || $bundle['prijs'] === null) {
                    continue;
                }

                if ($bundle['prijs'] > $base['prijs']) {
                    continue;
                }

                $found[] = $bundle['sku'].' ('.$maat.') — met onderkleed '.$this->euro($bundle['prijs'])
                    .', zonder onderkleed '.$this->euro($base['prijs']);
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $items
     * @return array<string, mixed>
     */
    private function check(
        string $group,
        string $key,
        string $label,
        string $status,
        string $value,
        string $detail,
        array $items,
    ): array {
        return [
            'group'  => $group,
            'key'    => $key,
            'label'  => $label,
            'status' => $status,
            'value'  => $value,
            'detail' => $detail,
            'items'  => $items,
            'count'  => $status === self::STATUS_OK ? 0 : count($items),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<string, CompetitorPrice>  $cheapest
     * @param  Collection<string, array<string, mixed>>  $products
     * @return array<string, list<array<string, mixed>>>
     */
    private function outliers(Collection $rows, Collection $cheapest, Collection $products): array
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
            'suspicious'    => $this->suspiciousCouplings($cheapest, $products, (float) $limits['competitor_ratio']),
            'stale'         => $this->stalePrices($cheapest, (int) $limits['stale_days']),
        ];
    }

    /**
     * Competitor prices so far below our adviesverkoopprijs that they are more
     * likely a different product than a bargain — the failure mode the audit
     * found: a fuzzy match couples us to another rug and drags the price down.
     *
     * @param  Collection<string, CompetitorPrice>  $cheapest
     * @param  Collection<string, array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function suspiciousCouplings(Collection $cheapest, Collection $products, float $ratioPct): array
    {
        return $cheapest
            ->filter(function (CompetitorPrice $price, string $sku) use ($products, $ratioPct): bool {
                $advies = $products->get($sku)['advies'] ?? null;

                return $advies !== null && (float) $price->price < $advies * $ratioPct / 100;
            })
            ->map(fn (CompetitorPrice $price, string $sku): array => [
                'sku'              => $sku,
                'shop'             => $price->shop,
                'competitor_price' => (float) $price->price,
                'advies'           => $products->get($sku)['advies'],
                'ratio'            => (float) $price->price / $products->get($sku)['advies'] * 100,
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
        $outliers = (array) config('competitor_pricing.report.outliers', []);
        $checks = (array) config('competitor_pricing.report.checks', []);

        return [
            'drop_pct'           => (float) ($outliers['drop_pct'] ?? 15),
            'rise_pct'           => (float) ($outliers['rise_pct'] ?? 15),
            'competitor_ratio'   => (float) ($outliers['competitor_ratio'] ?? 60),
            'stale_days'         => (int) ($outliers['stale_days'] ?? 14),
            'max_rows'           => (int) ($outliers['max_rows'] ?? 25),
            'min_refresh_pct'    => (float) ($checks['min_refresh_pct'] ?? 80),
            'shop_partial_pct'   => (float) ($checks['shop_partial_pct'] ?? 50),
            'shop_ratio_low'     => (float) ($checks['shop_ratio_low'] ?? 70),
            'shop_ratio_high'    => (float) ($checks['shop_ratio_high'] ?? 120),
            'dissent_pct'        => (float) ($checks['dissent_pct'] ?? 25),
            'psqm_deviation_pct' => (float) ($checks['psqm_deviation_pct'] ?? 40),
            'flapping_days'      => (int) ($checks['flapping_days'] ?? 5),
            'mass_change_pct'    => (float) ($checks['mass_change_pct'] ?? 25),
            'max_items'          => (int) ($checks['max_items'] ?? 15),
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

    /**
     * Every flagged item of every check, so the mail can show the first few and
     * still hand over the complete list.
     *
     * @param  list<array<string, mixed>>  $checks
     */
    public function checksToCsv(array $checks): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['Soort', 'Signaal', 'Status', 'Bevinding'], ';');

        foreach ($checks as $check) {
            foreach ($check['items'] as $item) {
                fputcsv($handle, [
                    $check['group'] === self::GROUP_PIPELINE ? 'Analyse' : 'Prijs',
                    $check['label'],
                    $check['status'] === self::STATUS_ALERT ? 'Alarm' : 'Let op',
                    $item,
                ], ';');
            }
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

    private function euro(float $value): string
    {
        return '€ '.number_format($value, 2, ',', '.');
    }

    /** "1 winkel" versus "3 winkels", so a count never reads as a typo. */
    private function plural(int $count, string $singular, string $plural): string
    {
        return $count.' '.($count === 1 ? $singular : $plural);
    }

    private function pct(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',').'%';
    }
}
