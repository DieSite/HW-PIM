<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiDescriptionRun extends Model
{
    protected $fillable = [
        'user_id',
        'filters',
        'fields',
        'sync_woo',
        'driver',
        'model',
        'matched_count',
        'generated_count',
        'failed_count',
        'status',
        'error',
        'finished_at',
    ];

    /**
     * @return HasMany<AiDescriptionDraft, $this>
     */
    public function drafts(): HasMany
    {
        return $this->hasMany(AiDescriptionDraft::class, 'run_id');
    }

    protected function casts(): array
    {
        return [
            'filters'     => 'array',
            'fields'      => 'array',
            'sync_woo'    => 'boolean',
            'finished_at' => 'datetime',
        ];
    }
}
