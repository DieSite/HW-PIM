<?php

/*
|--------------------------------------------------------------------------
| Standaard productafbeelding – admin configuration tree
|--------------------------------------------------------------------------
|
| Merged into the "core" config (the admin Configuration screen) from
| App\Providers\AppServiceProvider. De afbeelding wordt gebruikt door
| App\Services\DefaultProductImage wanneer een product zonder afbeeldingen
| naar WooCommerce gaat.
|
*/

return [
    [
        'key'  => 'product_images',
        'name' => 'Productafbeeldingen',
        'info' => 'Instellingen voor productafbeeldingen.',
        'sort' => 7,
    ],
    [
        'key'  => 'product_images.settings',
        'name' => 'Productafbeeldingen',
        'info' => 'De afbeelding die getoond wordt als een product geen afbeeldingen heeft.',
        'sort' => 1,
    ],
    [
        'key'    => 'product_images.settings.general',
        'name'   => 'Standaardafbeelding',
        'info'   => 'Wordt naar WooCommerce gestuurd voor een product zonder afbeeldingen. Leeg laten = zo\'n product wordt niet gesynct.',
        'sort'   => 1,
        'fields' => [
            [
                'name'  => 'default_image',
                'title' => 'Geen afbeelding beschikbaar',
                'type'  => 'image',
                'info'  => 'Bijvoorbeeld een "geen afbeelding beschikbaar"-afbeelding.',
            ],
        ],
    ],
];
