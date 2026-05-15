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

    public function bolSyncEvents(): HasMany
    {
        return $this->hasMany(BolSyncEvent::class, 'product_id')->orderByDesc('id');
    }

    public function lastBolSyncEvent(): BelongsTo
    {
        return $this->belongsTo(BolSyncEvent::class, 'bol_last_event_id');
    }

    protected function casts(): array
    {
        return [
            'bol_sync_state'    => BolSyncState::class,
            'bol_sync_state_at' => 'datetime',
        ];
    }
}
