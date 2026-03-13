<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class Product extends \Webkul\Product\Models\Product
{
    protected $table = 'products';

    protected $guarded = [];

    /**
     * Scope to products that have any stock.
     *
     * Stock is tracked per variant via these JSON fields in values.common.
     */
    public function scopeInStock(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            foreach (['voorraad_eurogros', 'voorraad_5_korting_handmatig', 'voorraad_hw_5_korting', 'uitverkoop_15_korting'] as $field) {
                $q->orWhereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(values, '$.common.{$field}')) AS UNSIGNED) > 0");
            }
        });
    }
}
