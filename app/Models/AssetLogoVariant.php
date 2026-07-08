<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\DAM\Models\Asset;

/**
 * Bookkeeping for HW-logo stamped image variants: which DAM asset was derived
 * (with the logo overlaid) from which source asset. Used to recognise images
 * that already carry the logo and to reuse a variant across products instead
 * of stamping the same source twice.
 */
class AssetLogoVariant extends Model
{
    protected $fillable = ['source_asset_id', 'variant_asset_id'];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'source_asset_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'variant_asset_id');
    }
}
