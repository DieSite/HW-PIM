<?php

namespace App\Services;

use App\Jobs\SyncWooCommerceStockJob;
use App\Models\BolComCredential;
use App\Models\DeMunkProductLink;
use App\Models\Product;

/**
 * Writes De Munk stock onto the size variants of linked PIM products.
 *
 * A link ties a design+colour configurable (e.g. DMC0014 "Diamante 01") to a
 * De Munk article identity. De Munk reports free stock per size, so each of the
 * configurable's size variants gets its own quantity, defaulting to 0 when that
 * size is not in stock. Both the "Zonder onderkleed" and "Met onderkleed"
 * variants of a size receive the same quantity, and only changed variants are
 * re-synced to WooCommerce / Bol.com.
 */
class DeMunkStockWriter
{
    private const STOCK_ATTRIBUTE = 'voorraad_5_korting_handmatig';

    public function __construct(private ProductService $productService) {}

    /**
     * Apply a fetched stock set to every active link.
     *
     * @param  list<array<string, mixed>>  $articles  Tagged WebArticles from DeMunkPortalClient
     * @return array{products:int, variants_changed:int}
     */
    public function apply(array $articles): array
    {
        $stockMap = self::buildStockMap($articles);
        $syncExternal = (bool) config('demunk.sync_external');
        $bolCredentials = $syncExternal ? BolComCredential::all()->all() : [];

        $productsTouched = 0;
        $variantsChanged = 0;
        $wcUpdates = [];

        $links = DeMunkProductLink::query()
            ->whereNotNull('demunk_kleur')
            ->with('product.variants')
            ->get();

        foreach ($links as $link) {
            $identityKey = self::identityKey($link->demunk_collectie, $link->demunk_kwaliteit, $link->demunk_kleur);
            $sizeStock = $stockMap[$identityKey] ?? [];

            $changed = $this->applyToProduct($link->product, $sizeStock, $wcUpdates, $bolCredentials, $syncExternal);

            if ($changed > 0) {
                $productsTouched++;
                $variantsChanged += $changed;
            }
        }

        if ($syncExternal && ! empty($wcUpdates)) {
            SyncWooCommerceStockJob::dispatch(array_values($wcUpdates));
        }

        return [
            'products'         => $productsTouched,
            'variants_changed' => $variantsChanged,
        ];
    }

    /**
     * Build identity => (maat => free quantity) from tagged De Munk articles.
     *
     * @param  list<array<string, mixed>>  $articles
     * @return array<string, array<string, int>>
     */
    public static function buildStockMap(array $articles): array
    {
        $map = [];

        foreach ($articles as $article) {
            $collectie = (string) ($article['_collectie'] ?? '');
            $kwaliteit = (string) ($article['_kwaliteit'] ?? '');
            $kleur = (string) ($article['EersteKleurVeld'] ?? '');
            $breedte = (int) ($article['Breedte'] ?? 0);
            $lengte = (int) ($article['Lengte'] ?? 0);

            if ($collectie === '' || $kwaliteit === '' || $kleur === '' || $breedte === 0 || $lengte === 0) {
                continue;
            }

            $maat = "{$breedte} cm x {$lengte} cm";
            $free = (int) ($article['ArticleStockFree'] ?? 0);

            $key = self::identityKey($collectie, $kwaliteit, $kleur);
            // A size can appear once per identity; keep the highest reported free stock.
            $map[$key][$maat] = max($map[$key][$maat] ?? 0, $free);
        }

        return $map;
    }

    /**
     * Write per-size stock onto one configurable's variants. Returns the number
     * of variants whose quantity actually changed.
     *
     * @param  array<string, int>  $sizeStock  maat => free quantity
     * @param  array<string, array<string, mixed>>  $wcUpdates  accumulator, keyed by SKU
     * @param  list<BolComCredential>  $bolCredentials
     */
    private function applyToProduct(?Product $product, array $sizeStock, array &$wcUpdates, array $bolCredentials, bool $syncExternal): int
    {
        if ($product === null) {
            return 0;
        }

        $changed = 0;

        foreach ($product->variants as $variant) {
            $values = $variant->values;
            $common = $values['common'] ?? [];

            $maat = $common['maat'] ?? null;

            if ($maat === null || $maat === 'Maatwerk') {
                continue;
            }

            $new = $sizeStock[$maat] ?? 0;
            $old = (int) ($common[self::STOCK_ATTRIBUTE] ?? 0);

            if ($new === $old) {
                continue;
            }

            $values['common'][self::STOCK_ATTRIBUTE] = $new;
            $variant->values = $values;
            $variant->save();

            if ($syncExternal) {
                $wcUpdates[$variant->sku] = WooCommerceStockSyncService::buildStockUpdate($variant);
                $this->productService->triggerBolSync($variant, $bolCredentials, [], true);
            }

            $changed++;
        }

        return $changed;
    }

    private static function identityKey(?string $collectie, ?string $kwaliteit, ?string $kleur): string
    {
        return strtoupper((string) $collectie).'|'.strtoupper((string) $kwaliteit).'|'.strtoupper((string) $kleur);
    }
}
