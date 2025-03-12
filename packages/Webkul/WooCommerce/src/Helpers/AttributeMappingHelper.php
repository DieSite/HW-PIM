<?php

namespace Webkul\WooCommerce\Helpers;

class AttributeMappingHelper
{
    /**
     * Returns the fields configuration for export mapping.
     */
    public static function getStandardFields(): array
    {
        return [
            [
                'name'          => 'sku',
                'types'         => ['text'],
                'unique'        => true,
                'required'      => true,
                'removed'       => false,
                'default_value' => false,
                'label'         => trans('woocommerce::app.mappings.attribute-mapping.field.sku.label'),
                'tooltip'       => trans('woocommerce::app.mappings.attribute-mapping.field.sku.info'),
            ],
            [
                'name'          => 'slug',
                'types'         => ['text'],
                'unique'        => true,
                'required'      => true,
                'removed'       => false,
                'default_value' => false,
                'label'         => trans('woocommerce::app.mappings.attribute-mapping.field.slug.label'),
                'tooltip'       => trans('woocommerce::app.mappings.attribute-mapping.field.slug.info'),
            ],
            [
                'name'          => 'name',
                'types'         => ['text'],
                'required'      => false,
                'default_value' => true,
                'label'         => trans('woocommerce::app.mappings.attribute-mapping.field.name.label'),
                'tooltip'       => trans('woocommerce::app.mappings.attribute-mapping.field.name.info'),
            ],
            [
                'name'          => 'regular_price',
                'types'         => ['price'],
                'required'      => false,
                'default_value' => true,
                'label'         => trans('woocommerce::app.mappings.attribute-mapping.field.regular_price.label'),
                'tooltip'       => trans('woocommerce::app.mappings.attribute-mapping.field.regular_price.info'),
            ],
            [
                'name'          => 'description',
                'types'         => ['textarea'],
                'required'      => false,
                'default_value' => true,
                'label'         => trans('woocommerce::app.mappings.attribute-mapping.field.description.label'),
                'tooltip'       => trans('woocommerce::app.mappings.attribute-mapping.field.description.info'),
            ],
            [
                'name'          => 'short_description',
                'types'         => ['textarea'],
                'required'      => false,
                'default_value' => true,
                'label'         => trans('woocommerce::app.mappings.attribute-mapping.field.short_description.label'),
                'tooltip'       => trans('woocommerce::app.mappings.attribute-mapping.field.short_description.info'),
            ],
            [
                'name'          => 'weight',
                'types'         => ['text'],
                'required'      => false,
                'default_value' => true,
                'label'         => trans('woocommerce::app.mappings.attribute-mapping.field.weight.label'),
                'tooltip'       => trans('woocommerce::app.mappings.attribute-mapping.field.weight.info'),
            ],
            [
                'name'          => 'Length',
                'types'         => ['text'],
                'required'      => false,
                'default_value' => true,
                'label'         => trans('woocommerce::app.mappings.attribute-mapping.field.length.label'),
                'tooltip'       => trans('woocommerce::app.mappings.attribute-mapping.field.length.info'),
            ],
            [
                'name'          => 'width',
                'types'         => ['text'],
                'required'      => false,
                'default_value' => true,
                'label'         => trans('woocommerce::app.mappings.attribute-mapping.field.width.label'),
                'tooltip'       => trans('woocommerce::app.mappings.attribute-mapping.field.width.info'),
            ],
            [
                'name'          => 'height',
                'types'         => ['text'],
                'required'      => false,
                'default_value' => true,
                'label'         => trans('woocommerce::app.mappings.attribute-mapping.field.height.label'),
                'tooltip'       => trans('woocommerce::app.mappings.attribute-mapping.field.height.info'),
            ],
            [
                'name'          => 'stock_quantity',
                'types'         => ['text'],
                'required'      => false,
                'default_value' => true,
                'label'         => trans('woocommerce::app.mappings.attribute-mapping.field.stock_quantity.label'),
                'tooltip'       => trans('woocommerce::app.mappings.attribute-mapping.field.stock_quantity.info'),
            ],
            [
                'name'          => 'featured',
                'types'         => ['boolean'],
                'required'      => false,
                'default_value' => true,
                'label'         => trans('woocommerce::app.mappings.attribute-mapping.field.featured.label'),
                'tooltip'       => trans('woocommerce::app.mappings.attribute-mapping.field.featured.info'),
            ],
            [
                'name'          => 'purchase_note',
                'types'         => ['text'],
                'required'      => false,
                'default_value' => true,
                'label'         => trans('woocommerce::app.mappings.attribute-mapping.field.purchase_note.label'),
                'tooltip'       => trans('woocommerce::app.mappings.attribute-mapping.field.purchase_note.info'),
            ],
            [
                'name'          => 'reviews_allowed',
                'types'         => ['boolean'],
                'required'      => false,
                'default_value' => true,
                'label'         => trans('woocommerce::app.mappings.attribute-mapping.field.reviews_allowed.label'),
                'tooltip'       => trans('woocommerce::app.mappings.attribute-mapping.field.reviews_allowed.info'),
            ],
            [
                'name'          => 'backorders_allowed',
                'types'         => ['boolean'],
                'required'      => false,
                'default_value' => true,
                'label'         => trans('woocommerce::app.mappings.attribute-mapping.field.backorders_allowed.label'),
                'tooltip'       => trans('woocommerce::app.mappings.attribute-mapping.field.backorders_allowed.info'),
            ],
        ];
    }
}
