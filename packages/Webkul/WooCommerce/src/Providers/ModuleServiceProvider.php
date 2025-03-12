<?php

namespace Webkul\WooCommerce\Providers;

use Webkul\Core\Providers\CoreModuleServiceProvider;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    protected $models = [
        \Webkul\WooCommerce\Models\Credential::class,
        \Webkul\WooCommerce\Models\Mapping::class,
        \Webkul\WooCommerce\Models\DataTransferMapping::class,
    ];
}
