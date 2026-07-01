<?php

namespace App\Models;

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
        'changed_at',
    ];

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
            'changed_at'       => 'datetime',
        ];
    }
}
