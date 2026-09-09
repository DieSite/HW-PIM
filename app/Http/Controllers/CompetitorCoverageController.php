<?php

namespace App\Http\Controllers;

use App\Models\CompetitorCoverageConfirmation;
use App\Models\CompetitorSignalReview;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * De knop "Klopt, geen concurrent gevonden" uit het dagrapport.
 *
 * Bereikbaar zónder inloggen, want hij wordt vanuit een mailclient geklikt; de
 * route is daarom ondertekend (`signed`). Dat is te verantwoorden omdat de
 * actie niets kapotmaakt: hij haalt één kleed uit een aandachtslijst, verandert
 * geen prijs en is met één regel SQL terug te draaien.
 */
class CompetitorCoverageController extends Controller
{
    /**
     * "Klopt wel" uit blok 1: het signaal is bekeken en is geen probleem.
     * Zonder deze knop staat hetzelfde kleed elke nacht opnieuw in de top-5 en
     * verdringt het de gevallen die nog niemand gezien heeft.
     */
    public function acknowledge(Request $request, string $sku): View
    {
        CompetitorSignalReview::updateOrCreate(
            ['sku' => $sku, 'shop' => ''],
            ['verdict' => CompetitorSignalReview::VERDICT_CONFIRMED, 'reviewed_at' => now()],
        );

        return view('pricing.signal-reviewed', [
            'titel'  => 'Genoteerd als in orde',
            'sku'    => $sku,
            'regels' => ['Dit kleed komt niet meer terug in "Vijf kleden waarvan de prijs waarschijnlijk niet klopt".'],
        ] + $this->productContext($sku));
    }

    public function confirm(Request $request, string $sku): View
    {
        $product = Product::query()->where('sku', $sku)->first();

        CompetitorCoverageConfirmation::updateOrCreate(
            ['sku' => $sku],
            ['confirmed_at' => now()],
        );

        $values = $product?->values;

        if (is_string($values)) {
            $values = json_decode($values, true);
        }

        $common = is_array($values) ? ($values['common'] ?? []) : [];

        return view('pricing.coverage-confirmed', [
            'sku'   => $sku,
            'model' => $common['productnaam'] ?? null,
            'maat'  => $common['maat'] ?? null,
        ]);
    }

    /**
     * Naam en maat bij een SKU, puur om de bevestigingspagina leesbaar te maken.
     *
     * @return array{model: ?string, maat: ?string}
     */
    private function productContext(string $sku): array
    {
        $values = Product::query()->where('sku', $sku)->value('values');

        if (is_string($values)) {
            $values = json_decode($values, true);
        }

        $common = is_array($values) ? ($values['common'] ?? []) : [];

        return ['model' => $common['productnaam'] ?? null, 'maat' => $common['maat'] ?? null];
    }
}
