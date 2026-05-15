<?php

namespace App\Models;

use App\Enums\BolSyncEventStatus;
use App\Enums\BolSyncStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BolSyncEvent extends Model
{
    protected $table = 'bol_com_sync_events';

    protected $fillable = [
        'product_id',
        'bol_com_credential_id',
        'step',
        'status',
        'message',
        'customer_message',
        'bol_process_id',
        'payload',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(BolComCredential::class, 'bol_com_credential_id');
    }

    protected function casts(): array
    {
        return [
            'step'    => BolSyncStep::class,
            'status'  => BolSyncEventStatus::class,
            'payload' => 'array',
        ];
    }
}
