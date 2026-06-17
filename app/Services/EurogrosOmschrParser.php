<?php

namespace App\Services;

class EurogrosOmschrParser
{
    /**
     * Resolve a Eurogros OMSCHR description into the productnaam + maat used to
     * locate the matching products in the PIM.
     *
     * Handles the size formats that occur in Voorraad_Eurogros.csv:
     *  - rectangular: "Anaheim 7626 120x170"        => [Anaheim 7626, 120 cm x 170 cm]
     *  - round:       "Manchester 2959 200 rond"    => [Manchester 2959, Rond 200 cm]
     *  - square:      "Twilight 2211 200x200 vierkant" => [Twilight 2211, 200 cm x 200 cm]
     *  - underlay:    "Anti Slip Basic 240x340 cm"  => [Anti Slip Basic, 240 cm x 340 cm]
     *  - oval:        "Twilight 2211 160x230ovaal"  => [Twilight 2211 Ovaal, 160 cm x 230 cm]
     *
     * Returns null when the description cannot be matched: organic-shape rugs
     * (not carried), custom sizes ("maatwerk"), startersets, or a size that is
     * not present in config/eurogros.maat_map.
     *
     * @return array{productnaam: string, maat: string}|null
     */
    public static function resolveMatch(string $omschr): ?array
    {
        $omschr = trim($omschr);

        /** @var array<string, string> $map */
        $map = config('eurogros.maat_map');

        if (preg_match('/^(.*?)\s+(\d+)\s*rond$/i', $omschr, $matches)) {
            $maat = $map[$matches[2].'rond'] ?? null;

            if ($maat === null) {
                return null;
            }

            return ['productnaam' => trim($matches[1]), 'maat' => $maat];
        }

        if (preg_match('/^(.*?)\s+(\d+x\d+)((?:\s*(?:ovaal|vierkant|organische\s+vorm|cm))*)$/i', $omschr, $matches)) {
            $suffix = strtolower($matches[3]);

            if (str_contains($suffix, 'organische')) {
                return null;
            }

            $maat = $map[strtolower($matches[2])] ?? null;

            if ($maat === null) {
                return null;
            }

            $productnaam = trim($matches[1]);

            if (str_contains($suffix, 'ovaal')) {
                $productnaam .= ' Ovaal';
            }

            return ['productnaam' => $productnaam, 'maat' => $maat];
        }

        return null;
    }
}
