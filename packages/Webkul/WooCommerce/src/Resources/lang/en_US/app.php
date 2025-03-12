<?php

return [
    'components' => [
        'layouts' => [
            'sidebar' => [
                'woocommerce'     => 'Woocommerce',
                'credentials'     => 'Credentials',
                'mappings'        => [
                    'title'                => 'Mappings',
                    'attribute-mapping'    => 'Attribute Mapping',
                ],
                'settings'        => [
                    'title' => 'Settings',
                ],
            ],
        ],
    ],

    'woocommerce' => [
        'credential' => [
            'index'     => [
                'title'                      => 'WooCommerce Credentials',
                'create'                     => 'Create Credential',
                'url'                        => 'Woocommerce URL',
                'woocommerceurlplaceholder'  => 'Woocommerce URL (http://example.com)',
                'consumerKey'                => 'Consumer Key',
                'consumerSecret'             => 'Consumer Secret',
                'save'                       => 'Save',
            ],
            'delete-success' => 'Credential Deleted Success',
            'created'        => 'Credential created Success',
            'update-success' => 'Credential Updated Success',
            'invalid'        => 'The provided url is invalid',
            'invalidurl'     => 'Invalid URL',
            'datagrid'       => [
                'shopUrl'                   => 'Woocommerce URL',
                'consumerKey'               => 'Consumer Key',
                'active'                    => 'Is Active',

            ],
            'edit' => [
                'title'    => 'Edit Credential',
                'delete'   => 'Delete Credential',
                'back-btn' => 'Back',
                'update'   => 'Update',
                'save'     => 'Save',
                'settings' => 'Settings',
                'active'   => 'Is Active',
                'tab'      => [
                    'credential-settings' => [
                        'label'          => 'Credential',
                        'update-success' => 'Credentials updated successfully',
                    ],
                    'attributeMapping' => [
                        'label' => 'Attribute Mapping',
                        'title' => 'Attribute Mapping',
                        'save'  => 'Save',
                    ],
                    'history' => [
                        'label' => 'History',
                    ],
                    'attribute-mapping' => [
                        'update-success' => 'Mapping updated Successfully',
                    ],
                ],

            ],
        ],
    ],
    'mappings' => [
        'attribute-mapping' => [
            'name'              => 'Attribute Mapping',
            'title'             => 'Attribute Mapping',
            'field-woocommerce' => 'WooCommerce Fields',
            'unopim-attribute'  => 'UnoPim Attributes',
            'fixed-value'       => 'Fixed Value',
            'save-button'       => 'Save',
            'success-response'  => 'Standard attribute mapping saved successfully.',
            'other'             => [
                'title' => 'Other Mappings',
            ],
            'images'            => [
                'title'         => 'Image Mapping',
                'label'         => [
                    'type'      => 'Media Type',
                    'attribute' => 'Media Attributes',
                ],
            ],
            'field'             => [
                'sku'                    => [
                    'label' => 'Sku',
                    'info'  => 'Map a unique & text type UnoPim attribute to the Sku field.',
                ],
                'slug'                    => [
                    'label' => 'Slug',
                    'info'  => 'Map a unique & text type UnoPim attribute to the Slug field.',
                ],
                'name'                   => [
                    'label' => 'Product Name',
                    'info'  => 'Map a text type UnoPim attribute to the Name field.',
                ],
                'regular_price'                   => [
                    'label' => 'Price',
                    'info'  => 'Map a price type UnoPim attribute to the Price field.',
                ],
                'stock_quantity'                   => [
                    'label' => 'Quantity',
                    'info'  => 'Map the unopim attribute to the Quantity field as a select type.',
                ],
                'featured'                   => [
                    'label' => 'Is Featured?',
                    'info'  => 'Map a boolean type UnoPim attribute to the featured field.',
                ],
                'weight'                   => [
                    'label' => 'Weight',
                    'info'  => 'Map a text type UnoPim attribute to the Weight field.',
                ],
                'length'                   => [
                    'label' => 'Length',
                    'info'  => 'Map a text type UnoPim attribute to the Length field.',
                ],
                'width'                   => [
                    'label' => 'Width',
                    'info'  => 'Map a text type UnoPim attribute to the Width field.',
                ],
                'height'                   => [
                    'label' => 'Height',
                    'info'  => 'Map a text type UnoPim attribute to the Height field.',
                ],
                'short_description'                   => [
                    'label' => 'Short Description',
                    'info'  => 'Map a textarea type UnoPim attribute to the short_description field.',
                ],
                'description'                   => [
                    'label' => 'Description',
                    'info'  => 'Map a textarea type UnoPim attribute to the description field.',
                ],
                'reviews_allowed'                   => [
                    'label' => 'Reviews allowed',
                    'info'  => 'Map a boolean type UnoPim attribute to the reviews_allowed field.',
                ],
                'backorders_allowed'                   => [
                    'label' => 'Backorders Allowed',
                    'info'  => 'Map a boolean type UnoPim attribute to the backorders_allowed field.',
                ],
                'purchase_note'                   => [
                    'label' => 'Purchase notes',
                    'info'  => 'Map a text type or select type UnoPim attribute to the purchase_note field.',
                ],
            ],

            'other-mapping'   => [
                'title'                => 'Other Mappings',
                'label'                => 'WooCommerce product field code',
                'enabled'              => 'Non Select Attribute send as Select Attribute (Recommended)',
                'desc'                 => 'Please type the woocommerce field codes that must exist in the store, or select attributes to add additional product field mappings.',
                'custom-mapping'       => 'Attributes to be used as Custom Attributes (must add variants attributes here)',
                'media-mapping'        => 'Attributes to be used as Images',
                'field-type'           => 'Field Type',
                'add'                  => 'Add Field',
                'add-success'          => 'Standard field added successfully',
                'remove-success'       => 'Standard field removed successfully',
                'flash-message'        => 'Please fill in both the attribute code and the type.',
                'placeholder'          => 'Type woocommerce Field code or Choose UnoPim Attribute',
                'tooltip'              => 'This is additional attribute',
                'success-update-field' => 'Standard Attribute Field updated successfully',
            ],

        ],
    ],
    'data-transfer' => [
        'exports' => [
            'type' => [
                'category'              => 'WooCommerce Category Export',
                'attribute'             => 'WooCommerce Attribute Export',
                'product'               => 'WooCommerce Product Export',
            ],
            'filters' => [
                'credential'                => 'WooCommerce Store URL',
                'attributes'                => 'Attributes',
                'additional-attributes'     => 'Additional Attributes from mapping',
                'channel'                   => 'Channel',
                'locale'                    => 'Locale',
                'currency'                  => 'Currency',
                'rootcategories'            => 'UnoPim Root Categories',
                'attributeSets'             => 'Unopim Attribute Families',
                'productSKU'                => "UnoPim Product SKU's",
                'with_media'                => 'With Media',
                'error'                     => [
                    'websites' => 'Empty websites filter or not mapped in store view mapping',
                ],
            ],
            'error' => [
                'invalid' => 'Invalid Credential.The credential is either disabled or incorrect',
            ],
        ],
        'imports' => [
            'type' => [
                'category'              => 'WooCommerce Category Import',
                'attribute'             => 'WooCommerce Attribute Import',
                'product'               => 'WooCommerce Product Import',
            ],
            'filters' => [
                'credential'               => 'WooCommerce Store URL',
                'attributes'               => 'Attributes',
                'additional-attributes'    => 'Additional Attributes from mapping',
                'channel'                  => 'Channel',
                'locale'                   => 'Locale',
                'currency'                 => 'Currency',
                'rootcategories'           => 'UnoPim Root Categories',
                'attributeSets'            => 'Unopim Attribute Families',
                'productSKU'               => "UnoPim Product SKU's",
                'error'                    => [
                    'websites' => 'Empty websites filter or not mapped in store view mapping',
                ],
            ],
            'error' => [
                'invalid' => 'Invalid Credential.The credential is either disabled or incorrect',
            ],
        ],
    ],
];
