<?php

/*
|--------------------------------------------------------------------------
| Simplified admin menu
|--------------------------------------------------------------------------
|
| The short menu a user can switch to at the top of the sidebar ("Eenvoudig"),
| instead of the full Webkul menu. Every entry is a top-level item.
|
| `permission` is the ACL key that decides whether the user sees the entry,
| the same key that guards it in the full menu (the Tools entries follow the
| `tools` menu item, as they do there).
|
*/

return [
    'cookie' => 'admin_menu',

    'items' => [
        [
            'key'        => 'eenvoudig-producten',
            'name'       => 'Producten',
            'route'      => 'admin.catalog.products.index',
            'permission' => 'catalog.products',
            'icon'       => 'icon-catalog',
        ],
        [
            'key'        => 'eenvoudig-productvoorraad',
            'name'       => 'Productvoorraad updaten',
            'route'      => 'admin.tools.product-stock-editor.index',
            'permission' => 'tools',
            'icon'       => 'icon-product',
        ],
        [
            'key'        => 'eenvoudig-showroomvoorraad',
            'name'       => 'Showroom voorraad updaten',
            'route'      => 'admin.tools.product-hw-stock-editor.index',
            'permission' => 'tools',
            'icon'       => 'icon-folder',
        ],
        [
            'key'        => 'eenvoudig-fouten',
            'name'       => 'Producten met fouten',
            'route'      => 'admin.tools.errored-products.index',
            'permission' => 'tools',
            'icon'       => 'icon-information',
        ],
        [
            'key'        => 'eenvoudig-hordeuren',
            'name'       => 'Hordeuren concurrentie-analyse',
            'route'      => 'admin.tools.hordeuren-analyse.index',
            'permission' => 'tools',
            'icon'       => 'icon-search',
        ],
        [
            'key'        => 'eenvoudig-concurrentieprijzen',
            'name'       => 'Concurrentieprijzen',
            'route'      => 'admin.tools.competitor-prices.index',
            'permission' => 'tools',
            'icon'       => 'icon-up-stat',
        ],
        [
            'key'        => 'eenvoudig-levertijden',
            'name'       => 'Levertijden',
            'route'      => 'admin.tools.delivery-times.index',
            'permission' => 'tools',
            'icon'       => 'icon-calendar',
        ],
    ],
];
