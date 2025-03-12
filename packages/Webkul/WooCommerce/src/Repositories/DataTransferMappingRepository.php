<?php

namespace Webkul\WooCommerce\Repositories;

use Webkul\Core\Eloquent\Repository;
use Webkul\WooCommerce\Contracts\DataTransferMapping;

class DataTransferMappingRepository extends Repository
{
    /**
     * Specify model class name.
     */
    public function model(): string
    {
        return DataTransferMapping::class;
    }
}
