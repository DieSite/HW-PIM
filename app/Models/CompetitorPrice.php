<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompetitorPrice extends Model
{
    protected $fillable = [
        'sku',
        'product_id',
        'shop',
        'price',
        'url',
        'scraped_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price'      => 'decimal:2',
            'scraped_at' => 'datetime',
        ];
    }
}
