<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The delivery time of every rug of one brand (the parent's `merk` value),
 * e.g. "1 tot 2 weken" in stock and "3 tot 5 weken" when it has to be ordered.
 */
class DeliveryTimeRule extends Model
{
    protected $fillable = [
        'brand',
        'in_stock',
        'out_of_stock',
    ];
}
