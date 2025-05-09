<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Webkul\Product\Models\Product;

class BolComCredential extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'client_id',
        'client_secret',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * The products that belong to this credential.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_bol_com_credentials')
            ->withPivot('delivery_code', 'reference')
            ->withTimestamps();
    }
}
