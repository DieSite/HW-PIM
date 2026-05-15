<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BolEconomicOperator extends Model
{
    protected $table = 'bol_economic_operators';

    protected $fillable = [
        'bol_com_credential_id',
        'bol_operator_id',
        'name',
        'external_reference',
        'status',
        'payload',
    ];

    public function credential(): BelongsTo
    {
        return $this->belongsTo(BolComCredential::class, 'bol_com_credential_id');
    }

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
