<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BulkEditRun extends Model
{
    protected $fillable = [
        'user_id',
        'target_attribute',
        'filters',
        'operation',
        'sync_woo',
        'matched_count',
        'changed_count',
        'status',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'filters'     => 'array',
            'operation'   => 'array',
            'sync_woo'    => 'boolean',
            'finished_at' => 'datetime',
        ];
    }
}
