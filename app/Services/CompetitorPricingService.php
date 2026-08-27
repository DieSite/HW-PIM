<?php

namespace App\Services;

use App\Models\CompetitorPrice;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class CompetitorPricingService
{
    public function __construct(private ProductService $productService) {}

    /**
     * Recompute the selling price for every product matching the given SKUs and
     * sync any product whose price changed to WooCommerce + Bol.com.
     *
     * @param  array<int, string>  $skus
     * @param  array<string, array<string, array{price: float, url: ?string}>>  $previousSnapshot
     *                                                                                             Competitor prices per SKU as they were BEFORE this import, used to
     *                                                                                             explain WHY our price changed. Keyed by sku, then shop.
     */
    public function recomputeForSkus(array $skus, array $previousSnapshot = []): void
    {
        $changedVariants = [];

        Product::whereIn('sku', array_values(array_unique($skus)))
            ->whereNotNull('parent_id')
            // De handmatige korting valt terug op de parent, dus die hoort in
            // dezelfde query mee — anders is het een lazy load per variant.
            ->with('parent')
            ->chunkById(200, function (Collection $variants) use (&$changedVariants, $previousSnapshot): void {
                foreach ($variants as $variant) {
                    $history = $this->recompute($variant, $previousSnapshot[$variant->sku] ?? []);

                    if ($history !== null) {
                        $changedVariants[] = $variant;
                    }

                    // Ook zónder prijswijziging afstemmen: de met-onderkleed-
                    // variant kan nog op een oude (of ooit fout gekoppelde)
                    // prijs staan, en dan is dit de reparatie.
                    $sibling = $this->applyUnderlayPrice($variant, $this->readPrice($variant, 'prijs') ?? 0.0);

                    if ($sibling !== null) {
                        $changedVariants[] = $sibling;
                    }
                }
            });

        // Een basisvariant en zijn onderkleed-broer kunnen allebei in dezelfde
        // batch zitten; dan staat de broer er tweemaal in.
        $changedVariants = collect($changedVariants)->unique('id')->values()->all();

        $this->dispatchSync($changedVariants);
    }

    /**
     * Recompute one variant's price. Returns the logged history row when the
     * price actually changed, or null when nothing changed / was skipped.
     *
     * @param  array<string, array{price: float, url: ?string}>  $previousForSku
     */
    public function recompute(Product $variant, array $previousForSku = []): ?ProductPriceHistory
    {
        // Een "Met onderkleed"-variant heeft geen eigen concurrentprijs: geen
        // enkele concurrent verkoopt die combinatie. Zijn prijs wordt afgeleid
        // van de kale broer (applyUnderlayPrice). Zonder deze grens schrijven
        // beide kanten hetzelfde veld en beslist de rij-volgorde wie wint —
        // en zolang de oude `.O`-rijen nog in `competitor_prices` staan, wint
        // in de natuurlijke volgorde de verkeerde.
        if ($this->onderkleed($variant) === 'Met onderkleed') {
            return null;
        }

        $advies = $this->readPrice($variant, 'adviesverkoopprijs');

        if ($advies === null || $advies <= 0) {
            return null;
        }

        $pct = $this->maxDiscountPercentage();
        $floor = $advies * (1 - $pct / 100);

        $competitors = CompetitorPrice::query()
            ->where('sku', $variant->sku)
            ->where('price', '>', 0)
            ->get();

        $lowest = $competitors->sortBy('price')->first();

        $lowestPrice = $lowest?->price !== null ? (float) $lowest->price : null;
        $newPrice = $this->computePrice($advies, $lowestPrice, $pct);

        /**
         * De handmatige korting gaat er ná de concurrentielogica overheen, dus
         * hij stapelt bewust: zakt een concurrent, dan zakt deze prijs mee én
         * blijft de korting erop staan. Hij breekt daarmee door de normale
         * bodem heen — dat is precies waarvoor het veld bestaat.
         */
        $manualPct = $this->manualDiscountPercentage($variant);

        if ($manualPct > 0.0) {
            $newPrice = (float) round($newPrice * (1 - $manualPct / 100));
        }

        $currentPrice = $this->readPrice($variant, 'prijs');

        if ($currentPrice !== null && abs($newPrice - $currentPrice) < 0.005) {
            return null;
        }

        $values = $variant->values;
        $values['common']['prijs']['EUR'] = (string) (int) $newPrice;
        $variant->values = $values;
        $variant->saveQuietly();

        $reason = $this->buildReason(
            advies: $advies,
            floor: $floor,
            pct: $pct,
            newPrice: $newPrice,
            competitors: $competitors,
            previousForSku: $previousForSku,
            lowest: $lowest,
            manualPct: $manualPct,
        );

        return ProductPriceHistory::create([
            'product_id'       => $variant->id,
            'sku'              => $variant->sku,
            'old_price'        => $currentPrice,
            'new_price'        => $newPrice,
            'reason'           => $reason,
            'competitor_shop'  => $lowest?->shop,
            'competitor_price' => $lowestPrice,
            'competitor_url'   => $lowest?->url,
            'changed_at'       => now(),
        ]);
    }

    /**
     * Carry a "Zonder onderkleed" price over to its "Met onderkleed" sibling as
     * price + the per-size underlay surcharge.
     *
     * Competitors sell the rug without an underlay, so the bundle has no
     * competitor price of its own; deriving it here keeps the surcharge intact
     * while the discount still reaches the customer.
     *
     * This runs on EVERY processed base variant, not only when its price just
     * changed — that is what repairs siblings still sitting on an old or once
     * wrongly coupled price. The met-onderkleed price is therefore a DERIVED
     * field: a manual edit on it is overwritten on the next run. Returns the
     * sibling only when its price actually changed, so the caller can sync it.
     */
    public function applyUnderlayPrice(Product $variant, float $newPrice): ?Product
    {
        // Zonder bruikbare basisprijs valt er niets af te leiden. Anders zou de
        // kale toeslag (€30) als verkoopprijs in de winkel belanden — de
        // basisvariant heeft dan namelijk geen prijs én geen adviesprijs, dus
        // recompute() heeft hem hierboven al overgeslagen.
        if ($newPrice <= 0) {
            return null;
        }

        if ($this->onderkleed($variant) !== 'Zonder onderkleed') {
            return null;
        }

        $common = $this->common($variant);

        $sibling = $this->productService->getUnderrugAlternative($variant);

        if ($sibling === null) {
            return null;
        }

        $size = trim((string) ($common['maat'] ?? ''));
        $surcharge = $this->productService->underrugSurcharge($variant);

        if ($surcharge === null) {
            Log::warning('Geen onderkleed-toeslag voor maat; met-onderkleed-variant niet bijgewerkt', [
                'sku'  => $sibling->sku,
                'maat' => $size,
            ]);

            return null;
        }

        $target = $newPrice + (float) $surcharge;

        // Alleen een PLAFOND, geen bodem. De kortingsbodem bestaat om onszelf
        // niet onder een concurrent uit te prijzen; een afgeleide bundelprijs
        // heeft geen concurrent, en de basisprijs is al gefloord — die bodem
        // erft de bundel gratis. `computePrice` hierop loslaten maakte van een
        // scheve adviesprijs een prijsabsurditeit (€2.250 voor een kleed van
        // €1.000 plus €30 onderkleed).
        $siblingAdvies = $this->readPrice($sibling, 'adviesverkoopprijs');
        $baseAdvies = $this->readPrice($variant, 'adviesverkoopprijs');

        if ($siblingAdvies !== null && $siblingAdvies > 0) {
            if ($baseAdvies !== null && abs($siblingAdvies - ($baseAdvies + $surcharge)) > 1) {
                Log::warning('Adviesprijs van de onderkleed-variant loopt niet gelijk met de kale variant + toeslag', [
                    'sku'             => $sibling->sku,
                    'advies_bundel'   => $siblingAdvies,
                    'advies_verwacht' => $baseAdvies + $surcharge,
                ]);
            }

            $target = min($target, $siblingAdvies);
        }

        // Een bundel die goedkoper uitkomt dan het kale kleed is nooit juist.
        // Dat kan alleen als de adviesprijzen scheefstaan; dan liever niets
        // schrijven dan een onzinnige prijs de winkel in duwen.
        if ($target < $newPrice) {
            Log::warning('Afgeleide bundelprijs zou onder de kale variant uitkomen; niet bijgewerkt', [
                'sku'           => $sibling->sku,
                'basisprijs'    => $newPrice,
                'advies_bundel' => $siblingAdvies,
            ]);

            return null;
        }

        $current = $this->readPrice($sibling, 'prijs');

        if ($current !== null && abs($target - $current) < 0.005) {
            return null;
        }

        $values = $sibling->values;
        $values['common']['prijs']['EUR'] = (string) (int) $target;
        $sibling->values = $values;
        $sibling->saveQuietly();

        ProductPriceHistory::create([
            'product_id' => $sibling->id,
            'sku'        => $sibling->sku,
            'old_price'  => $current,
            'new_price'  => $target,
            'reason'     => sprintf(
                'Afgeleid van %s (zonder onderkleed, € %s) + € %s onderkleedtoeslag voor %s',
                $variant->sku,
                number_format($newPrice, 2, ',', '.'),
                number_format((float) $surcharge, 2, ',', '.'),
                $size !== '' ? $size : 'onbekende maat',
            ),
            'changed_at' => now(),
        ]);

        return $sibling;
    }

    /**
     * The dynamic selling price: the lowest of the advies price and the cheapest
     * competitor, but never more than `pct`% below the advies price. Rounded to
     * whole euros. With no competitor, it is exactly the advies price.
     *
     * The rounding happens BEFORE the bounds are re-applied, because rounding a
     * clamped value can push it back out: advies 100,60 rounded to 101 sits
     * above the advies price, and a floor of 299,25 rounds down to 299. The
     * advies price is a hard ceiling (we never sell above it), so when no whole
     * euro fits between floor and advies the ceiling wins.
     */
    public function computePrice(float $advies, ?float $lowest, float $pct): float
    {
        $floor = $advies * (1 - $pct / 100);
        $target = $lowest === null ? $advies : min($advies, $lowest);

        $price = round(max($floor, $target), 0);

        if ($price > $advies) {
            $price = floor($advies);
        }

        if ($price < $floor && ceil($floor) <= $advies) {
            $price = ceil($floor);
        }

        return (float) $price;
    }

    /**
     * The maximum allowed discount (%) below the adviesverkoopprijs. Admin
     * editable; falls back to the config default when unset/invalid.
     */
    /**
     * The handmatige extra korting (%) for one rug: read from the variant, and
     * otherwise from its parent so a whole model can be discounted in one
     * place without a second mechanism for "the whole model".
     *
     * Deliberately NOT capped at the confirmation threshold — a bigger number
     * has already been confirmed by a human at every route into the field, and
     * quietly shaving it down would produce a price nobody chose. It is only
     * logged, so an unusual discount leaves a trail.
     *
     * A value outside 0–100 is refused rather than applied: at 100% or more
     * there is no price left to sell, and a negative would be a markup. That
     * is not a discount anyone confirmed, it is a broken value.
     */
    public function manualDiscountPercentage(Product $variant): float
    {
        $raw = $variant->values['common']['extra_korting']
            ?? $variant->parent?->values['common']['extra_korting']
            ?? null;

        if (! is_numeric($raw)) {
            return 0.0;
        }

        $pct = (float) $raw;

        if ($pct <= 0.0) {
            return 0.0;
        }

        if ($pct >= 100.0) {
            Log::error('Extra korting genegeerd: buiten 0–100%', [
                'sku'           => $variant->sku,
                'extra_korting' => $raw,
            ]);

            return 0.0;
        }

        if ($pct > (float) config('competitor_pricing.manual_discount_confirm_pct')) {
            Log::warning('Ongebruikelijk hoge handmatige korting toegepast', [
                'sku'           => $variant->sku,
                'extra_korting' => $pct,
            ]);
        }

        return $pct;
    }

    public function maxDiscountPercentage(): float
    {
        $configured = core()->getConfigData('general.pricing.settings.max_kortingspercentage');
        $pct = is_numeric($configured) ? (float) $configured : (float) config('competitor_pricing.default_max_discount_pct');

        if ($pct < 0 || $pct >= 100) {
            return (float) config('competitor_pricing.default_max_discount_pct');
        }

        return $pct;
    }

    /**
     * @param  Collection<int, CompetitorPrice>  $competitors
     * @param  array<string, array{price: float, url: ?string}>  $previousForSku
     */
    public function buildReason(
        float $advies,
        float $floor,
        float $pct,
        float $newPrice,
        Collection $competitors,
        array $previousForSku,
        ?CompetitorPrice $lowest,
        float $manualPct = 0.0,
    ): string {
        $clamped = $lowest !== null && (float) $lowest->price < $floor;

        $suffix = $clamped ? ' (begrensd op adviesprijs −'.rtrim(rtrim(number_format($pct, 2, ',', '.'), '0'), ',').'%).' : '';

        /**
         * Zonder deze regel is een handmatig afgeprijsd kleed niet te
         * onderscheiden van een kleed dat door een concurrent omlaag is
         * getrokken — en dan weet niemand meer waarom het zo goedkoop staat.
         */
        if ($manualPct > 0.0) {
            $suffix .= ' Daarna handmatige extra korting −'
                .rtrim(rtrim(number_format($manualPct, 2, ',', '.'), '0'), ',')
                .'% toegepast (eindprijs '.$this->euro($newPrice).').';
        }

        if ($lowest === null) {
            return 'Teruggezet naar adviesprijs ('.$this->euro($advies).'): geen concurrent goedkoper.'.$suffix;
        }

        $lowestShop = $lowest->shop;
        $lowestPrice = (float) $lowest->price;

        // Determine which competitor was lowest before this import.
        $prevLowestShop = null;
        $prevLowestPrice = null;
        foreach ($previousForSku as $shop => $data) {
            if ($prevLowestPrice === null || $data['price'] < $prevLowestPrice) {
                $prevLowestPrice = (float) $data['price'];
                $prevLowestShop = $shop;
            }
        }

        if ($prevLowestShop === null) {
            return 'Concurrent '.$lowestShop.' biedt '.$this->euro($lowestPrice).' — laagste concurrent.'.$suffix;
        }

        if ($prevLowestShop === $lowestShop) {
            if ($lowestPrice < $prevLowestPrice) {
                return 'Concurrent '.$lowestShop.' verlaagde naar '.$this->euro($lowestPrice).' — nieuwe laagste prijs.'.$suffix;
            }

            return 'Concurrent '.$lowestShop.' verhoogde naar '.$this->euro($lowestPrice).', maar blijft de laagste.'.$suffix;
        }

        // A different competitor is now the lowest. If the previous leader raised
        // its price, spell that out; otherwise state the new leader.
        $prevLeaderNow = $competitors->firstWhere('shop', $prevLowestShop);

        if ($prevLeaderNow !== null && (float) $prevLeaderNow->price > $prevLowestPrice) {
            return 'Concurrent '.$prevLowestShop.' verhoogde naar '.$this->euro((float) $prevLeaderNow->price)
                .'; '.$lowestShop.' is nu de laagste met '.$this->euro($lowestPrice).'.'.$suffix;
        }

        return $lowestShop.' is nu de laagste concurrent met '.$this->euro($lowestPrice).'.'.$suffix;
    }

    /**
     * Dispatch the external syncs for changed variants: WooCommerce once per
     * parent family, Bol.com per synced variant.
     *
     * @param  array<int, Product>  $changedVariants
     */
    public function dispatchSync(array $changedVariants): void
    {
        $syncedParents = [];

        foreach ($changedVariants as $variant) {
            $parent = $variant->parent;

            if ($parent !== null && ! isset($syncedParents[$parent->id])) {
                $this->productService->triggerWCSyncForParent($parent);
                $syncedParents[$parent->id] = true;
            }

            $ean = $variant->values['common']['ean'] ?? null;

            if ($variant->bol_com_sync && $ean !== null) {
                $this->productService->triggerBolSync(
                    $variant,
                    $variant->bolComCredentials->all(),
                    [],
                    true,
                );
            }
        }
    }

    /**
     * The `common` scope of a product's values, tolerating the double-encoded
     * `values` column some legacy rows still carry.
     *
     * @return array<string, mixed>
     */
    private function common(Product $product): array
    {
        $values = $product->values;

        if (is_string($values)) {
            $values = json_decode($values, true);
        }

        return is_array($values) ? ($values['common'] ?? []) : [];
    }

    /** The onderkleed attribute, or null when the product does not carry one. */
    private function onderkleed(Product $product): ?string
    {
        $value = $this->common($product)['onderkleed'] ?? null;

        return is_string($value) ? $value : null;
    }

    private function readPrice(Product $product, string $code): ?float
    {
        $value = $product->values['common'][$code]['EUR'] ?? null;

        return $value === null || $value === '' ? null : (float) $value;
    }

    private function euro(float $value): string
    {
        return '€ '.number_format($value, 2, ',', '.');
    }
}
