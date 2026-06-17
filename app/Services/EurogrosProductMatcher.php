<?php

namespace App\Services;

class EurogrosProductMatcher
{
    /**
     * Trailing words in a productnaam that denote a shape rather than the design.
     *
     * @var list<string>
     */
    private const SHAPE_WORDS = [
        'Rond', 'Ovaal', 'Oval', 'Organic', 'Organische', 'Wing', 'Eye',
        'Plaza', 'Eclipse', 'Dice', 'Leaf', 'Hexagon', 'Vierkant', 'Square',
    ];

    /**
     * Break a productnaam + maat into a comparable descriptor
     * [collectionPrefix, articleNumber, shape] used to match Eurogros rows to
     * PIM products. Works for both sides:
     *   "Antiquarian Antique Heriz 8703", "240 cm x 340 cm" => ["antiquarian antique heriz", "8703", null]
     *   "Anaheim 4248 Rond",              "Rond 200 cm"     => ["anaheim", "4248", "rond"]
     *   "Twilight 2211 Ovaal",            "160 cm x 230 cm" => ["twilight", "2211", "oval"]
     *
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    public static function describe(string $productnaam, string $maat): array
    {
        $shape = self::deriveShape($productnaam, $maat);

        // Strip a trailing shape word, normalise hyphens (e.g. "7252-100" -> "7252 100").
        $naam = preg_replace('/\s+('.implode('|', self::SHAPE_WORDS).')$/i', '', trim($productnaam));
        $naam = str_replace('-', ' ', (string) $naam);
        $parts = preg_split('/\s+/', trim($naam)) ?: [];

        $number = null;
        if (count($parts) > 1 && preg_match('/^\d+$/', (string) end($parts))) {
            $number = ltrim((string) array_pop($parts), '0');
            if ($number === '') {
                $number = '0';
            }
        }

        return [strtolower(implode(' ', $parts)), $number, $shape];
    }

    /**
     * Only round and oval are treated as distinguishing shapes; everything else
     * (square, rectangle, …) collapses to null because its size already differs.
     */
    public static function deriveShape(string $productnaam, string $maat): ?string
    {
        if (preg_match('/\s+(ovaal|oval)$/i', trim($productnaam))) {
            return 'oval';
        }

        if (stripos($maat, 'rond') !== false || preg_match('/\s+rond$/i', trim($productnaam))) {
            return 'rond';
        }

        return null;
    }

    /**
     * A CSV descriptor matches a PIM descriptor when the article number and shape
     * are identical and the PIM collection starts with the (shorter) CSV
     * collection — the PIM name may carry extra sub-collection words.
     *
     * @param  array{0: string, 1: ?string, 2: ?string}  $csv
     * @param  array{0: string, 1: ?string, 2: ?string}  $pim
     */
    public static function isMatch(array $csv, array $pim): bool
    {
        [$csvColl, $csvNum, $csvShape] = $csv;
        [$pimColl, $pimNum, $pimShape] = $pim;

        if ($csvNum === null || $pimNum === null || $csvNum !== $pimNum) {
            return false;
        }

        if ($csvShape !== $pimShape) {
            return false;
        }

        if ($csvColl === '' || $pimColl === '') {
            return false;
        }

        return $pimColl === $csvColl || str_starts_with($pimColl.' ', $csvColl.' ');
    }
}
