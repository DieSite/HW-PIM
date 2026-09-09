<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One competitor coupling that disappeared: the scrape stopped reporting a
 * price for this (sku, shop) and `--prune` removed it.
 *
 * Kept because the deletion itself is the news. Losing the only competitor for
 * a rug pushes its selling price straight back up to the adviesverkoopprijs,
 * and without this log the report could only show the price move, never the
 * reason behind it.
 */
class CompetitorPriceRemoval extends Model
{
    protected $fillable = [
        'sku',
        'shop',
        'price',
        'url',
        'scraped_at',
        'removed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price'      => 'decimal:2',
            'scraped_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }
}
