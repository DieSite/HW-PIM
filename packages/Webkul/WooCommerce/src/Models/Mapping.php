<?php

namespace Webkul\WooCommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\WooCommerce\Contracts\Mapping as MappingContract;

class Mapping extends Model implements MappingContract
{
    protected $table = 'wk_woocommerce_credentials';

    protected $fillable = [
        'extras',
    ];

    protected $casts = [
        'extras'  => 'array',
    ];
}
