<?php

namespace App\Services;

use App\Models\CompetitorPrice;
use App\Models\CompetitorPriceRemoval;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * The part of the daily report someone can act on.
 *
 * The report proper answers "was de run gezond" — useful, but it grew into a
 * wall of numbers nobody opens. This answers a different question: what
 * changed in our coverage that a human has to do something about. Four blocks,
 * each with a concrete next step; the fifth block (the prices most likely to be
 * wrong) needs the reporter's own analysis and lives there.
 *
 * Every block reports its full total and hands back at most `max_rows` items,
 * so the mail stays short while the CSV keeps everything.
 */
class CompetitorAnalysisActions
{
    /**
     * @return array<string, array{title: string, action: string, total: int, items: list<array<string, mixed>>}>
     */
    public function build(CarbonInterface $since, CarbonInterface $until, int $maxRows): array
    {
        return [
            'new_products' => $this->newProducts($since, $until, $maxRows),
            'new_prices'   => $this->newlyCovered($since, $until, $maxRows),
            'lost_prices'  => $this->lostCoverage($since, $until, $maxRows),
            'no_coverage'  => $this->withoutAnyCompetitor($maxRows),
        ];
    }

    /**
     * Rugs added to the PIM since the previous run.
     *
     * A new variant only starts being priced against the competition once it
     * has an adviesverkoopprijs — `CompetitorPricingService::recompute()` skips
     * it outright without one — and nothing fills that field on create. That is
     * the whole reason this block exists: a new rug can sit in the shop for
     * weeks at an unguarded price without anything complaining.
     *
     * @return array{title: string, action: string, total: int, items: list<array<string, mixed>>}
     */
    private function newProducts(CarbonInterface $since, CarbonInterface $until, int $maxRows): array
    {
        $query = Product::query()
            ->whereNotNull('parent_id')
            ->whereBetween('created_at', [$since, $until]);

        $total = (clone $query)->count();

        $variants = (clone $query)
            ->orderByDesc('created_at')
            ->limit($maxRows)
            ->selectRaw(
                'sku, created_at, '
                ."`values`->>'$.common.productnaam' as model, "
                ."`values`->>'$.common.maat' as maat, "
                ."`values`->>'$.common.prijs.EUR' as prijs, "
                ."`values`->>'$.common.adviesverkoopprijs.EUR' as advies, "
                ."`values`->>'$.common.onderkleed' as onderkleed"
            )
            ->get();

        $coverage = $this->competitorCounts($variants->pluck('sku')->all());

        $items = $variants->map(function ($variant) use ($coverage): array {
            $advies = $this->number($variant->advies);
            $competitors = $coverage[$variant->sku] ?? 0;
            $bundle = $this->text($variant->onderkleed) === 'Met onderkleed';

            return [
                'sku'         => $variant->sku,
                'model'       => $this->text($variant->model),
                'maat'        => $this->text($variant->maat),
                'prijs'       => $this->number($variant->prijs),
                'advies'      => $advies,
                'competitors' => $competitors,
                'actie'       => match (true) {
                    // Een bundel hoort geen concurrent te hebben: geen winkel
                    // verkoopt de combinatie, dus "nog geen concurrent" is hier
                    // de normale toestand en geen actie.
                    $bundle            => 'Prijs wordt afgeleid van de variant zonder onderkleed — controleer die',
                    $advies === null   => 'Vul de adviesverkoopprijs — zonder plafond blijft dit kleed buiten de prijsberekening',
                    $competitors === 0 => 'Nog geen concurrent gevonden; controleer of het merk bij een winkel in shops.js staat',
                    default            => 'Doet mee in de prijsberekening',
                },
            ];
        })->all();

        return [
            'title'  => 'Nieuwe kleden in de PIM',
            'action' => 'Controleer de adviesverkoopprijs: zonder dat veld wordt de prijs van een nieuw kleed door niets bewaakt.',
            'total'  => $total,
            'items'  => $items,
        ];
    }

    /**
     * Rugs that got their first competitor price.
     *
     * "First" means the first row this table knows of, so a rug that lost its
     * coverage earlier and regained it now shows up again — which is the same
     * news for a reader: there is suddenly a competitor steering this price.
     *
     * @return array{title: string, action: string, total: int, items: list<array<string, mixed>>}
     */
    private function newlyCovered(CarbonInterface $since, CarbonInterface $until, int $maxRows): array
    {
        $firstSeen = CompetitorPrice::query()
            ->selectRaw('sku, MIN(created_at) as first_seen')
            ->groupBy('sku')
            ->havingRaw('MIN(created_at) >= ? AND MIN(created_at) <= ?', [$since, $until])
            ->pluck('first_seen', 'sku');

        $skus = $firstSeen->keys()->all();
        $shown = array_slice($skus, 0, $maxRows);

        $cheapest = CompetitorPrice::query()
            ->whereIn('sku', $shown)
            ->orderBy('price')
            ->get()
            ->groupBy('sku')
            ->map(fn (Collection $prices): CompetitorPrice => $prices->first());

        $products = $this->productPrices($shown);
        $changed = ProductPriceHistory::query()
            ->whereIn('sku', $shown)
            ->whereBetween('changed_at', [$since, $until])
            ->pluck('new_price', 'sku');

        $items = collect($shown)->map(function (string $sku) use ($cheapest, $products, $changed): array {
            $competitor = $cheapest->get($sku);
            $product = $products[$sku] ?? ['prijs' => null, 'advies' => null, 'model' => null, 'maat' => null];

            return [
                'sku'              => $sku,
                'model'            => $product['model'],
                'maat'             => $product['maat'],
                'shop'             => $competitor?->shop,
                'concurrentprijs'  => $competitor === null ? null : (float) $competitor->price,
                'url'              => $competitor?->url,
                'prijs'            => $product['prijs'],
                'advies'           => $product['advies'],
                'afkeur_url'       => $competitor === null ? null
                    : $this->couplingMailUrl($sku, $product, $competitor),
                'actie'            => $changed->has($sku)
                    ? 'Prijs is hierdoor aangepast — controleer of de gekoppelde pagina hetzelfde kleed is'
                    : 'Geen prijseffect (concurrent is niet goedkoper); wel controleren of de koppeling klopt',
            ];
        })->all();

        return [
            'title'  => 'Kleden met voor het eerst een concurrentprijs',
            'action' => 'Open de concurrentpagina en kijk of het echt hetzelfde kleed in dezelfde maat is — een nieuwe koppeling is het moment waarop een foute match onze prijs voor het eerst verlaagt.',
            'total'  => count($skus),
            'items'  => $items,
        ];
    }

    /**
     * Couplings that disappeared: the scrape no longer finds this rug at that
     * shop, so the price it used to hold is gone.
     *
     * Losing the last competitor is the sharp case — the selling price jumps
     * straight back to the adviesverkoopprijs, which customers see as a price
     * increase — so it is called out separately.
     *
     * @return array{title: string, action: string, total: int, items: list<array<string, mixed>>}
     */
    private function lostCoverage(CarbonInterface $since, CarbonInterface $until, int $maxRows): array
    {
        $removals = CompetitorPriceRemoval::query()
            ->whereBetween('removed_at', [$since, $until])
            ->orderByDesc('price')
            ->get();

        $bySku = $removals->groupBy('sku');
        $shown = $bySku->keys()->take($maxRows);

        $remaining = CompetitorPrice::query()
            ->whereIn('sku', $shown->all())
            ->selectRaw('sku, COUNT(*) as aantal')
            ->groupBy('sku')
            ->pluck('aantal', 'sku');

        $products = $this->productPrices($shown->all());

        $items = $shown->map(function (string $sku) use ($bySku, $remaining, $products): array {
            $lost = $bySku->get($sku);
            $left = (int) ($remaining[$sku] ?? 0);
            $product = $products[$sku] ?? ['prijs' => null, 'advies' => null, 'model' => null, 'maat' => null];

            return [
                'sku'            => $sku,
                'model'          => $product['model'],
                'maat'           => $product['maat'],
                'shops'          => $lost->pluck('shop')->unique()->implode(', '),
                'laatste_prijs'  => (float) $lost->max('price'),
                'url'            => $lost->first()->url,
                'prijs'          => $product['prijs'],
                'advies'         => $product['advies'],
                'resterend'      => $left,
                'actie'          => $left === 0
                    ? 'Laatste concurrent weg — prijs gaat terug naar de adviesprijs. Controleer of de winkel het kleed echt niet meer voert.'
                    : 'Nog '.$left.' andere '.($left === 1 ? 'concurrent' : 'concurrenten').'; alleen controleren als de prijs hierdoor omhoog ging',
            ];
        })->values()->all();

        return [
            'title'  => 'Kleden die we niet meer bij de concurrent vinden',
            'action' => 'Verdwijnt een koppeling terwijl de winkel het kleed nog wel verkoopt, dan is er iets mis met de spec of de pagina-URL — dat kost ons stilletjes de scherpste prijs.',
            'total'  => $bySku->count(),
            'items'  => $items,
        ];
    }

    /**
     * Rugs no competitor sells at all, as far as the scrape can tell.
     *
     * Their price is by definition the adviesverkoopprijs: nothing pushes it
     * down. Sorted by adviesprijs so the most expensive blind spots come first,
     * because that is where an unnoticed missing coupling costs the most.
     *
     * Bundles ("Met onderkleed") and custom sizes are left out: their price is
     * derived, respectively never comparable to a competitor page.
     *
     * @return array{title: string, action: string, total: int, items: list<array<string, mixed>>}
     */
    private function withoutAnyCompetitor(int $maxRows): array
    {
        $query = Product::query()
            ->whereNotNull('parent_id')
            ->whereRaw("COALESCE(`values`->>'$.common.onderkleed', '') <> 'Met onderkleed'")
            ->whereRaw("COALESCE(`values`->>'$.common.maat', '') NOT LIKE '%aatwerk%'")
            ->whereRaw("`values`->>'$.common.adviesverkoopprijs.EUR' IS NOT NULL")
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('competitor_prices')
                    ->whereColumn('competitor_prices.sku', 'products.sku');
            })
            // Wat iemand al heeft nagekeken hoort er niet elke nacht opnieuw in
            // te staan; anders blijft dit blok duizenden regels groot en leest
            // niemand hem meer.
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('competitor_coverage_confirmations')
                    ->whereColumn('competitor_coverage_confirmations.sku', 'products.sku');
            });

        $total = (clone $query)->count();

        $items = (clone $query)
            ->selectRaw(
                'sku, '
                ."`values`->>'$.common.productnaam' as model, "
                ."`values`->>'$.common.maat' as maat, "
                ."`values`->>'$.common.prijs.EUR' as prijs, "
                ."CAST(`values`->>'$.common.adviesverkoopprijs.EUR' AS DECIMAL(12,2)) as advies"
            )
            ->orderByDesc('advies')
            ->limit($maxRows)
            ->get()
            ->map(function ($variant): array {
                $model = $this->text($variant->model);
                $maat = $this->text($variant->maat);

                return [
                    'sku'          => $variant->sku,
                    'model'        => $model,
                    'maat'         => $maat,
                    'prijs'        => $this->number($variant->prijs),
                    'advies'       => $this->number($variant->advies),
                    'bevestig_url' => $this->confirmUrl($variant->sku),
                    'mail_url'     => $this->reportUrl($variant->sku, $model, $maat, $this->number($variant->prijs)),
                    'actie'        => 'Zoek het model bij een concurrent; staat het merk niet in shops.js, dan wordt het nooit geïndexeerd',
                ];
            })
            ->all();

        return [
            'title'  => 'Kleden zonder enige concurrentprijs',
            'action' => 'Deze kleden staan altijd op de adviesprijs, want niets duwt hem omlaag. De duurste staan bovenaan: daar levert een ontbrekende koppeling de meeste misgelopen scherpte op.',
            'total'  => $total,
            'items'  => $items,
        ];
    }

    /**
     * "Koppeling klopt niet" bij een nieuwe koppeling: dezelfde vooringevulde
     * mail naar support als in blok 1, met de gegevens die nodig zijn om hem
     * na te lopen.
     *
     * @param  array{model: ?string, maat: ?string, prijs: ?float, advies: ?float}  $product
     */
    private function couplingMailUrl(string $sku, array $product, CompetitorPrice $competitor): string
    {
        $naam = trim(implode(' · ', array_filter([$product['model'], $product['maat']])));
        $euro = fn (?float $v): string => $v === null ? 'onbekend' : '€ '.number_format($v, 2, ',', '.');

        $body = implode("\n", array_filter([
            'Deze koppeling in de concurrentie-analyse klopt niet:',
            '',
            'SKU: '.$sku,
            $naam === '' ? null : 'Kleed: '.$naam,
            'Onze prijs: '.$euro($product['prijs']),
            '',
            'Gekoppeld aan: '.$competitor->shop.' — '.$euro((float) $competitor->price),
            'Pagina: '.($competitor->url ?: 'onbekend'),
            '',
            'Wat er mis is: ',
            '',
        ]));

        return 'mailto:'.config('competitor_pricing.report.support_address')
            .'?subject='.rawurlencode('Foute koppeling: '.$sku.' ↔ '.$competitor->shop)
            .'&body='.rawurlencode($body);
    }

    /**
     * Link achter "Klopt, geen concurrent gevonden".
     *
     * Ondertekend en zonder vervaldatum: de knop wordt uit een mail geklikt,
     * soms dagen later, en een verlopen link maakt de knop stilletjes stuk.
     * De handtekening is wat telt — zonder sessie is dit de enige manier om te
     * weten dat de link uit ons eigen rapport komt.
     */
    private function confirmUrl(string $sku): string
    {
        return URL::signedRoute('pricing.coverage.confirm', ['sku' => $sku]);
    }

    /**
     * Link achter "Mail url van concurrent": een vooringevulde mail naar de
     * supportafdeling. Bewust een mailto en geen formulier — de lezer heeft de
     * URL, wij niet, en een mailclient is precies waar hij op dat moment zit.
     */
    private function reportUrl(string $sku, ?string $model, ?string $maat, ?float $prijs): string
    {
        $naam = trim(implode(' · ', array_filter([$model, $maat])));

        $body = implode("\n", array_filter([
            'Ik heb dit kleed wél bij een concurrent gevonden:',
            '',
            'SKU: '.$sku,
            $naam === '' ? null : 'Kleed: '.$naam,
            $prijs === null ? null : 'Onze prijs: € '.number_format($prijs, 2, ',', '.'),
            '',
            'URL bij de concurrent: ',
            '',
        ]));

        return 'mailto:'.config('competitor_pricing.report.support_address')
            .'?subject='.rawurlencode('Concurrent gevonden voor '.$sku)
            .'&body='.rawurlencode($body);
    }

    /**
     * How many competitor prices each of these SKUs currently has.
     *
     * @param  array<int, string>  $skus
     * @return array<string, int>
     */
    private function competitorCounts(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        return CompetitorPrice::query()
            ->whereIn('sku', $skus)
            ->selectRaw('sku, COUNT(*) as aantal')
            ->groupBy('sku')
            ->pluck('aantal', 'sku')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * Name, size and both prices per SKU, straight from the JSON column.
     *
     * @param  array<int, string>  $skus
     * @return array<string, array{model: ?string, maat: ?string, prijs: ?float, advies: ?float}>
     */
    private function productPrices(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        return Product::query()
            ->whereIn('sku', $skus)
            ->selectRaw(
                'sku, '
                ."`values`->>'$.common.productnaam' as model, "
                ."`values`->>'$.common.maat' as maat, "
                ."`values`->>'$.common.prijs.EUR' as prijs, "
                ."`values`->>'$.common.adviesverkoopprijs.EUR' as advies"
            )
            ->get()
            ->mapWithKeys(fn ($variant): array => [$variant->sku => [
                'model'  => $this->text($variant->model),
                'maat'   => $this->text($variant->maat),
                'prijs'  => $this->number($variant->prijs),
                'advies' => $this->number($variant->advies),
            ]])
            ->all();
    }

    /** A JSON path returns the string "null" for a missing key; that is not a value. */
    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && $value !== 'null' ? $value : null;
    }

    private function number(mixed $value): ?float
    {
        $text = $this->text($value);

        return $text === null || ! is_numeric($text) || (float) $text <= 0 ? null : (float) $text;
    }
}
