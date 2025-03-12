<?php

namespace Webkul\WooCommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\WooCommerce\Contracts\DataTransferMapping as DataTransferMappingContract;

class DataTransferMapping extends Model implements DataTransferMappingContract
{
    protected $table = 'wk_woocommerce_data_transfer_mappings';

    protected $fillable = [
        'entityType',
        'code',
        'externalId',
        'jobInstanceId',
        'relatedId',
        'credentialId',
    ];
}
