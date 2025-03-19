<?php

return [
    'WooCommerceQuickExport' => [
        'title'    => 'WooCommerce Quick Export Product',
        'route'    => 'woocommerce.quick_export',
        'exporter' => 'Webkul\WooCommerce\Helpers\Exporters\Product\Exporter',
        'source'   => 'Webkul\Product\Repositories\ProductRepository',
    ],
];
