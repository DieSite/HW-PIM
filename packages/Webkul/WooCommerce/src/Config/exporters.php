<?php

return [
    'WooCommerceCategories' => [
        'title'    => 'woocommerce::app.data-transfer.exports.type.category',
        'exporter' => 'Webkul\WooCommerce\Helpers\Exporters\Category\Exporter',
        'source'   => 'Webkul\Category\Repositories\CategoryRepository',
        'filters'  => [
            'fields' => [
                [
                    'name'       => 'credential',
                    'title'      => 'woocommerce::app.data-transfer.exports.filters.credential',
                    'required'   => true,
                    'validation' => 'required',
                    'type'       => 'select',
                    'async'      => true,
                    'track_by'   => 'id',
                    'label_by'   => 'label',
                    'list_route' => 'woocommerce.credentials.get',
                ],
                [
                    'name'       => 'channel',
                    'title'      => 'woocommerce::app.data-transfer.exports.filters.channel',
                    'required'   => true,
                    'validation' => 'required',
                    'type'       => 'select',
                    'async'      => true,
                    'track_by'   => 'id',
                    'label_by'   => 'label',
                    'list_route' => 'woocommerce.channel.get',
                    'dependent'  => ['locale'],
                ],
                [
                    'name'       => 'locale',
                    'title'      => 'woocommerce::app.data-transfer.exports.filters.locale',
                    'required'   => true,
                    'validation' => 'required',
                    'type'       => 'select',
                    'async'      => true,
                    'track_by'   => 'id',
                    'label_by'   => 'label',
                    'list_route' => 'woocommerce.locale.get',
                ],
            ],
        ],
    ],

    'WooCommerceAttributes' => [
        'title'    => 'woocommerce::app.data-transfer.exports.type.attribute',
        'exporter' => 'Webkul\WooCommerce\Helpers\Exporters\Attribute\Exporter',
        'source'   => 'Webkul\Attribute\Repositories\AttributeRepository',
        'filters'  => [
            'fields' => [
                [
                    'name'       => 'credential',
                    'title'      => 'woocommerce::app.data-transfer.exports.filters.credential',
                    'required'   => true,
                    'validation' => 'required',
                    'type'       => 'select',
                    'async'      => true,
                    'track_by'   => 'id',
                    'label_by'   => 'label',
                    'list_route' => 'woocommerce.credentials.get',
                    'dependent'  => ['attributes'],
                ],
                [
                    'name'       => 'channel',
                    'title'      => 'woocommerce::app.data-transfer.exports.filters.channel',
                    'required'   => true,
                    'validation' => 'required',
                    'type'       => 'select',
                    'async'      => true,
                    'track_by'   => 'id',
                    'label_by'   => 'label',
                    'list_route' => 'woocommerce.channel.get',
                    'dependent'  => ['locale'],
                ],
                [
                    'name'       => 'locale',
                    'title'      => 'woocommerce::app.data-transfer.exports.filters.locale',
                    'required'   => true,
                    'validation' => 'required',
                    'type'       => 'select',
                    'async'      => true,
                    'track_by'   => 'id',
                    'label_by'   => 'label',
                    'list_route' => 'woocommerce.locale.get',
                ],
                [
                    'name'       => 'attributes',
                    'title'      => 'woocommerce::app.data-transfer.exports.filters.additional-attributes',
                    'required'   => false,
                    'type'       => 'multiselect',
                    'async'      => true,
                    'track_by'   => 'code',
                    'label_by'   => 'label',
                    'list_route' => 'woocommerce.exporters.attribute.filter.attributes.get',
                ],
            ],
        ],
    ],

    'WooCommerceProduct' => [
        'title'    => 'woocommerce::app.data-transfer.exports.type.product',
        'exporter' => 'Webkul\WooCommerce\Helpers\Exporters\Product\Exporter',
        'source'   => 'Webkul\Product\Repositories\ProductRepository',
        'filters'  => [
            'fields' => [
                [
                    'name'       => 'credential',
                    'title'      => 'woocommerce::app.data-transfer.exports.filters.credential',
                    'required'   => true,
                    'validation' => 'required',
                    'type'       => 'select',
                    'async'      => true,
                    'track_by'   => 'id',
                    'label_by'   => 'label',
                    'list_route' => 'woocommerce.credentials.get',
                ],
                [
                    'name'       => 'channel',
                    'title'      => 'woocommerce::app.data-transfer.exports.filters.channel',
                    'required'   => true,
                    'validation' => 'required',
                    'type'       => 'select',
                    'async'      => true,
                    'track_by'   => 'id',
                    'label_by'   => 'label',
                    'list_route' => 'woocommerce.channel.get',
                    'dependent'  => ['locale'],
                ],
                [
                    'name'       => 'locale',
                    'title'      => 'woocommerce::app.data-transfer.exports.filters.locale',
                    'required'   => true,
                    'validation' => 'required',
                    'type'       => 'select',
                    'async'      => true,
                    'track_by'   => 'id',
                    'label_by'   => 'label',
                    'list_route' => 'woocommerce.locale.get',
                ],
                [
                    'name'       => 'currency',
                    'title'      => 'woocommerce::app.data-transfer.exports.filters.currency',
                    'required'   => true,
                    'validation' => 'required',
                    'type'       => 'select',
                    'async'      => true,
                    'track_by'   => 'id',
                    'label_by'   => 'label',
                    'list_route' => 'woocommerce.currency.get',
                ],
                [
                    'name'       => 'productSKU',
                    'title'      => 'woocommerce::app.data-transfer.exports.filters.productSKU',
                    'required'   => false,
                    'type'       => 'multiselect',
                    'async'      => true,
                    'track_by'   => 'sku',
                    'label_by'   => 'sku',
                    'list_route' => 'woocommerce.exporters.filter.productSKU.get',
                ],
                [
                    'name'       => 'with_media',
                    'title'      => 'woocommerce::app.data-transfer.exports.filters.with_media',
                    'type'       => 'boolean',
                ],
            ],
        ],
    ],
];
