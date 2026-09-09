<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bevestiging dat geen enkele concurrent dit kleed voert.
 *
 * Gezet vanuit het dagrapport, zodat het blok "kleden zonder enige
 * concurrentprijs" krimpt tot wat nog niemand bekeken heeft in plaats van elke
 * nacht dezelfde duizenden regels te tonen.
 */
class CompetitorCoverageConfirmation extends Model
{
    protected $fillable = ['sku', 'confirmed_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }
}
