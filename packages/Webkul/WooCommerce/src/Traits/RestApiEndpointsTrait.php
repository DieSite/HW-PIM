<?php

namespace Webkul\WooCommerce\Traits;

/**
 * trait used to getApiClient Api EndPoints.
 */
trait RestApiEndpointsTrait
{
    private $apiEndpoints = [
        'products' => [
            'url'    => 'settings',
            'method' => 'GET',
        ],
        'addProduct' => [
            'url'    => 'products',
            'method' => 'POST',
        ],
        'addProductImages' => [
            'url'    => 'products/uploads/',
            'method' => 'POST',
        ],
        'getProduct' => [
            'url'    => 'products/{_id}',
            'method' => 'GET',
        ],
        'getWpmlProduct' => [
            'url'    => 'products',
            'method' => 'GET',
        ],
        'getAllProduct' => [
            'url'    => 'products',
            'method' => 'GET',
        ],
        'getAllProductByPage' => [
            'url'    => 'products?page={_page}',
            'method' => 'GET',
        ],
        'getAllVariableProductByPage' => [
            'url'    => 'products?page={_page}&type={_type}',
            'method' => 'GET',
        ],
        'updateProduct' => [
            'url'    => 'products/{_id}',
            'method' => 'PUT',
        ],
        'addVariation' => [
            'url'    => 'products/{_product}/variations',
            'method' => 'POST',
        ],
        'getVariation' => [
            'url'    => 'products/{_product}/variations',
            'method' => 'GET',
        ],
        'getVariationById' => [
            'url'    => 'products/{_product}/variations/{_variationid}',
            'method' => 'GET',
        ],
        'updateVariation' => [
            'url'    => 'products/{_product}/variations/{_id}',
            'method' => 'PUT',
        ],
        'addAttribute' => [
            'url'    => 'products/attributes',
            'method' => 'POST',
        ],
        'addProductAttribute' => [
            'url'    => 'products/attribute',
            'method' => 'POST',
        ],
        'getAttributes' => [
            'url'    => 'products/attributes',
            'method' => 'GET',
        ],
        'getAttribute' => [
            'url'    => 'products/attributes/{_id}',
            'method' => 'GET',
        ],
        'updateAttribute' => [
            'url'    => 'products/attributes/{_id}',
            'method' => 'PUT',
        ],
        'getOptions' => [
            'url'    => 'products/attributes',
            'method' => 'GET',
        ],
        'addOption' => [
            'url'    => 'products/attributes/{_attribute}/terms',
            'method' => 'POST',
        ],
        'getOption' => [
            'url'    => 'products/attributes/{_attribute}/terms/{_id}',
            'method' => 'GET',
        ],
        'getAttributeOption' => [
            'url'    => 'products/attributes/{_attribute}/terms/',
            'method' => 'GET',
        ],
        'updateOption' => [
            'url'    => 'products/attributes/{_attribute}/terms/{_id}',
            'method' => 'PUT',
        ],
        'getAllOption' => [
            'url'    => 'products/attributes/{_attribute}/terms?page={_page}',
            'method' => 'GET',
        ],
        'addCategory' => [
            'url'    => 'products/categories',
            'method' => 'POST',
        ],
        'getCategory' => [
            'url'    => 'products/categories',
            'method' => 'GET',
        ],
        'updateCategory' => [
            'url'    => 'products/categories/{_id}',
            'method' => 'POST',
        ],
        'getCategories' => [
            'url'    => 'products/categories?per_page={_per_page}&page={_page}&orderby={_orderby}',
            'method' => 'GET',
        ],
        'settings' => [
            'url'    => 'products',
            'method' => 'GET',
        ],
        'addMedia' => [
            'url'    => 'media',
            'method' => 'POST',
        ],
        'getSystemStatus' => [
            'url'    => 'system_status',
            'method' => 'GET',
        ],
        'deleteProduct' => [
            'url'    => 'products/{_id}',
            'method' => 'DELETE',
        ],
        'deleteProductVariant' => [
            'url'    => 'products/{_product}/variations/{_id}',
            'method' => 'DELETE',
        ],
        'deleteCategory' => [
            'url'    => 'products/categories/{_id}',
            'method' => 'DELETE',
        ],
        'deleteAttribute' => [
            'url'    => 'products/attributes/{_id}',
            'method' => 'DELETE',
        ],
        'deleteAttributeOptions' => [
            'url'    => 'products/attributes/{_attribute}/terms/{_id}',
            'method' => 'DELETE',
        ],
        'catalogSummary' => [
            'url'    => 'catalog/summary',
            'method' => 'GET',
        ],
        'addWpmlProduct' => [
            'url'    => 'products/variations',
            'method' => 'POST',
        ],
        'getACFField' => [
            'url'    => 'product',
            'method' => 'OPTIONS',
        ],
        'getMedia' => [
            'url'    => 'media/{_id}',
            'method' => 'GET',
        ],
        'getAllGroup' => [
            'url'    => 'wp-json/akeneo/v1/get-all-group',
            'method' => 'GET',
        ],
    ];
}
