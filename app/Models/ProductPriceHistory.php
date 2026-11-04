<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Product\Models\Product;

class ProductPriceHistory extends Model
{
    protected $table = 'product_price_history';

    protected $fillable = [
        'product_id',
        'sku',
        'old_price',
        'new_price',
        'reason',
        'competitor_shop',
        'competitor_price',
        'competitor_url',
        'basis',
        'basis_shops',
        'changed_at',
    ];

    /**
     * Rows where our selling price actually moved. The history also records
     * what changed behind an unchanged price (another competitor now sets
     * it, a shop joined the lowest price); those rows have
     * old_price = new_price and are no price change.
     *
     * @param  Builder<self>  $query
     */
    public function scopePriceChanges(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNull('old_price')
            ->orWhereColumn('old_price', '<>', 'new_price'));
    }

    public function keepsPrice(): bool
    {
        return $this->old_price !== null && abs((float) $this->old_price - (float) $this->new_price) < 0.005;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_price'        => 'decimal:2',
            'new_price'        => 'decimal:2',
            'competitor_price' => 'decimal:2',
            'basis_shops'      => 'array',
            'changed_at'       => 'datetime',
        ];
    }
}
