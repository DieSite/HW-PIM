<?php

namespace App\Models;

use App\Enums\WooCommerceSyncEventStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WooCommerceSyncEvent extends Model
{
    protected $table = 'woocommerce_sync_events';

    protected $fillable = [
        'product_id',
        'action',
        'status',
        'message',
        'customer_message',
        'external_id',
        'payload',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return [
            'status'  => WooCommerceSyncEventStatus::class,
            'payload' => 'array',
        ];
    }
}
