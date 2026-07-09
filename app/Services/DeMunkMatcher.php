<?php

namespace App\Services;

use App\Models\DeMunkProductLink;
use Illuminate\Support\Facades\DB;

/**
 * Links De Munk in-stock articles to PIM products (SKU prefix DMC).
 *
 * The mapping is deterministic: a De Munk article's collection + quality word +
 * colour number + shape maps onto a PIM product's `collectie`, `productnaam`
 * ("<Quality> <NN>") and `vorm`. For example the De Munk article
 * "MODERN DIAMANTE / DI-08" links to our "Diamante 08" (collectie Modern,
 * vorm Rechthoek). Ambiguous De Munk shape variants (e.g. "NAPOLI SPE") are left
 * for manual linking rather than guessed.
 *
 * @phpstan-type Identity array{collectie:string, kwaliteit:string, kleur:string, vorm:string}
 */
class DeMunkMatcher
{
    /**
     * A perfect deterministic match; fuzzy fallbacks would score lower.
     */
    private const DETERMINISTIC_SCORE = 100;

    /**
     * Build/refresh auto links from a set of De Munk articles.
     *
     * Locked (manually curated) links are never touched. Returns a summary of
     * what changed plus the De Munk identities that found no PIM product.
     *
     * @param  list<array<string, mixed>>  $articles  Tagged WebArticles from DeMunkPortalClient
     * @return array{linked:int, skipped_locked:int, unmatched:list<Identity>}
     */
    public function sync(array $articles): array
    {
        $pimIndex = $this->buildPimIndex();

        $linked = 0;
        $skippedLocked = 0;
        $unmatched = [];

        foreach ($this->distinctIdentities($articles) as $identity) {
            $vorm = self::vormClassForQuality($identity['kwaliteit']);

            if ($vorm === null) {
                $unmatched[] = $identity;

                continue;
            }

            $key = self::productKey(
                $identity['collectie'],
                self::qualityWord($identity['kwaliteit']),
                self::colourNumber($identity['kleur']),
                $vorm,
            );

            $productId = $pimIndex[$key] ?? null;

            if ($productId === null) {
                $unmatched[] = $identity;

                continue;
            }

            $existing = DeMunkProductLink::query()->where('product_id', $productId)->first();

            if ($existing && $existing->locked) {
                $skippedLocked++;

                continue;
            }

            DeMunkProductLink::query()->updateOrCreate(
                ['product_id' => $productId],
                [
                    'demunk_collectie' => $identity['collectie'],
                    'demunk_kwaliteit' => $identity['kwaliteit'],
                    'demunk_kleur'     => $identity['kleur'],
                    'demunk_vorm'      => $vorm,
                    'match_score'      => self::DETERMINISTIC_SCORE,
                    'source'           => 'auto',
                    'locked'           => false,
                ],
            );

            $linked++;
        }

        return [
            'linked'         => $linked,
            'skipped_locked' => $skippedLocked,
            'unmatched'      => $unmatched,
        ];
    }

    /**
     * Composite lookup key shared by PIM products and De Munk identities.
     */
    public static function productKey(string $collectie, string $qualityWord, int $number, string $vorm): string
    {
        return strtoupper($collectie).'|'.strtoupper($qualityWord).'|'.$number.'|'.$vorm;
    }

    /**
     * Map a De Munk quality to our `vorm`, or null when it cannot be trusted.
     *
     * "DIAMANTE" -> Rechthoek, "DIAMANTE R" -> Rond. Suffixes like "S", "ROD",
     * "SPE" are left unmatched for manual linking.
     */
    public static function vormClassForQuality(string $quality): ?string
    {
        $parts = preg_split('/\s+/', trim($quality)) ?: [];

        if (count($parts) === 1) {
            return 'Rechthoek';
        }

        if (count($parts) === 2 && strtoupper($parts[1]) === 'R') {
            return 'Rond';
        }

        return null;
    }

    /**
     * The quality/design word — first token of a De Munk quality or our productnaam.
     */
    public static function qualityWord(string $value): string
    {
        return preg_match('/^([\p{L}]+)/u', trim($value), $m) ? $m[1] : '';
    }

    /**
     * The design number embedded in a productnaam ("Diamante 08" -> 8).
     */
    public static function productnaamNumber(string $productnaam): ?int
    {
        return preg_match('/(\d+)/', $productnaam, $m) ? (int) $m[1] : null;
    }

    /**
     * The colour number from a De Munk colour code ("DI-08" -> 8).
     */
    public static function colourNumber(string $kleur): int
    {
        return preg_match('/(\d+)/', $kleur, $m) ? (int) $m[1] : 0;
    }

    /**
     * Reduce the article list to unique (collection, quality, colour) identities.
     *
     * @param  list<array<string, mixed>>  $articles
     * @return list<Identity>
     */
    private function distinctIdentities(array $articles): array
    {
        $identities = [];

        foreach ($articles as $article) {
            $collectie = (string) ($article['_collectie'] ?? '');
            $kwaliteit = (string) ($article['_kwaliteit'] ?? '');
            $kleur = (string) ($article['EersteKleurVeld'] ?? '');

            if ($collectie === '' || $kwaliteit === '' || $kleur === '') {
                continue;
            }

            $identities[$collectie.'|'.$kwaliteit.'|'.$kleur] = [
                'collectie' => $collectie,
                'kwaliteit' => $kwaliteit,
                'kleur'     => $kleur,
                'vorm'      => '',
            ];
        }

        return array_values($identities);
    }

    /**
     * Index PIM design products by their expected De Munk identity.
     *
     * Only rectangular and round configurable DMC products carry De Munk stock;
     * the exotic cut shapes (Wing/Eye/Organic/…) are made to order.
     *
     * @return array<string, int> key => product_id
     */
    private function buildPimIndex(): array
    {
        $rows = DB::table('products')
            ->selectRaw("id,
                JSON_UNQUOTE(JSON_EXTRACT(`values`, '$.common.collectie')) AS collectie,
                JSON_UNQUOTE(JSON_EXTRACT(`values`, '$.common.productnaam')) AS productnaam,
                JSON_UNQUOTE(JSON_EXTRACT(`values`, '$.common.vorm')) AS vorm")
            ->where('type', 'configurable')
            ->where('sku', 'like', config('demunk.brand_sku_prefix').'%')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(`values`, '$.common.vorm')) IN ('Rechthoek', 'Rond')")
            ->get();

        $index = [];

        foreach ($rows as $row) {
            $word = self::qualityWord((string) $row->productnaam);
            $number = self::productnaamNumber((string) $row->productnaam);

            if ($word === '' || $number === null || empty($row->collectie)) {
                continue;
            }

            $key = self::productKey((string) $row->collectie, $word, $number, (string) $row->vorm);

            // First writer wins; a collision would indicate duplicate PIM data.
            $index[$key] ??= (int) $row->id;
        }

        return $index;
    }
}
