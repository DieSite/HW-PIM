<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eén menselijk oordeel over een signaal uit het dagrapport.
 *
 * @see database/migrations/*_create_competitor_signal_reviews_table.php
 */
class CompetitorSignalReview extends Model
{
    /** Dit signaal is bekeken en klopt niet als probleem: niet meer tonen. */
    public const VERDICT_CONFIRMED = 'confirmed';

    protected $fillable = ['sku', 'shop', 'verdict', 'reviewed_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }
}
