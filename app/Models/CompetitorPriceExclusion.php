<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\User\Models\Admin;

/**
 * A competitor price taken out of the price analysis by hand, for one size
 * (sku), one shop and one exact amount. See CompetitorPrice::scopeNotExcluded().
 */
class CompetitorPriceExclusion extends Model
{
    protected $fillable = [
        'sku',
        'shop',
        'price',
        'reason',
        'admin_id',
    ];

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }
}
