<?php

namespace App\Models;

use App\Enums\BolSyncState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends \Webkul\Product\Models\Product
{
    protected $table = 'products';

    protected $guarded = [];

    /**
     * Scope to products that have any stock.
     *
     * Stock is tracked per variant via these JSON fields in values.common.
     */
    public function scopeInStock($query)
    {
        return $query->where(function (Builder $q) {
            foreach (['voorraad_eurogros', 'voorraad_5_korting_handmatig', 'voorraad_hw_5_korting', 'uitverkoop_15_korting'] as $field) {
                $q->orWhereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(`values`, '$.common.{$field}')) AS UNSIGNED) > 0");
            }
        });
    }

    /**
     * Scope to products that carry a non-empty minimale_prijs (EUR).
     *
     * Stored per variant in the currency-keyed JSON field values.common.minimale_prijs.
     */
    public function scopeHasMinimalePrijs($query)
    {
        return $query
            ->whereRaw("`values`->>'$.common.minimale_prijs.EUR' IS NOT NULL")
            ->whereRaw("`values`->>'$.common.minimale_prijs.EUR' != ''");
    }

    /**
     * Products eligible to be listed on Bol.com: they have an EAN, Eurogros
     * stock, and are the variant without an underlay ("Zonder onderkleed").
     */
    public function scopeBolSyncEligible(Builder $query): Builder
    {
        return $query
            ->whereRaw("`values`->>'$.common.ean' IS NOT NULL")
            ->whereRaw("`values`->>'$.common.ean' != ''")
            ->whereRaw("CAST(`values`->>'$.common.voorraad_eurogros' AS DECIMAL(10,2)) > 0")
            ->whereRaw("`values`->>'$.common.onderkleed' = 'Zonder onderkleed'");
    }

    public function bolSyncEvents(): HasMany
    {
        return $this->hasMany(BolSyncEvent::class, 'product_id')->orderByDesc('id');
    }

    public function lastBolSyncEvent(): BelongsTo
    {
        return $this->belongsTo(BolSyncEvent::class, 'bol_last_event_id');
    }

    public function wooCommerceSyncEvents(): HasMany
    {
        return $this->hasMany(WooCommerceSyncEvent::class, 'product_id')->orderByDesc('id');
    }

    protected function casts(): array
    {
        return [
            'bol_sync_state'    => BolSyncState::class,
            'bol_sync_state_at' => 'datetime',
        ];
    }
}
