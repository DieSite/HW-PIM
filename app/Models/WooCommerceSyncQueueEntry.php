<?php

namespace App\Models;

use App\Services\WooCommerce\WooCommerceSyncQueue;
use Database\Factories\WooCommerceSyncQueueEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A product waiting for (or busy with) a WooCommerce sync.
 *
 * Waiting entries have no claimed_at; an entry is claimed when its sync job
 * is put on the Redis queue and removed once that job and the variants it
 * chains have finished. {@see WooCommerceSyncQueue}
 *
 * @property int $id
 * @property int $product_id
 * @property array<int, int>|null $next_product_ids
 * @property Carbon|null $claimed_at
 * @property bool $rerun_requested
 * @property Carbon $created_at
 */
class WooCommerceSyncQueueEntry extends Model
{
    /** @use HasFactory<WooCommerceSyncQueueEntryFactory> */
    use HasFactory;

    protected $table = 'woocommerce_sync_queue';

    protected $fillable = [
        'product_id',
        'next_product_ids',
        'claimed_at',
        'rerun_requested',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeWaiting(Builder $query): void
    {
        $query->whereNull('claimed_at');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeClaimed(Builder $query): void
    {
        $query->whereNotNull('claimed_at');
    }

    protected function casts(): array
    {
        return [
            'next_product_ids' => 'array',
            'claimed_at'       => 'datetime',
            'rerun_requested'  => 'boolean',
        ];
    }
}
