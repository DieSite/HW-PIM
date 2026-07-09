<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a PIM design+colour product (configurable, SKU prefix DMC) to a
 * De Munk article identity (collection + quality + colour + shape variant),
 * so imported stock can be written to the product's size variants.
 *
 * A row with null De Munk coordinates represents a user-suppressed product
 * that has no De Munk source and should not be auto-matched again.
 */
class DeMunkProductLink extends Model
{
    protected $table = 'demunk_product_links';

    protected $fillable = [
        'product_id',
        'demunk_collectie',
        'demunk_kwaliteit',
        'demunk_kleur',
        'demunk_vorm',
        'match_score',
        'source',
        'locked',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function isLinked(): bool
    {
        return $this->demunk_kleur !== null;
    }

    protected function casts(): array
    {
        return [
            'match_score' => 'integer',
            'locked'      => 'boolean',
        ];
    }
}
