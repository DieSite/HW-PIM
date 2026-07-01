<?php

/*
|--------------------------------------------------------------------------
| Primary image editor – admin configuration tree
|--------------------------------------------------------------------------
|
| Merged into the "core" config (the admin Configuration screen) from
| App\Providers\AppServiceProvider. Only the HW icon needs to be admin
| configurable; the compositing geometry lives in config/product_image_editor.php.
|
*/

return [
    [
        'key'  => 'image_editor',
        'name' => 'Hoofdafbeelding',
        'info' => 'Instellingen voor het automatisch bewerken van de hoofdafbeelding.',
        'sort' => 6,
    ],
    [
        'key'  => 'image_editor.settings',
        'name' => 'Hoofdafbeelding',
        'info' => 'Het HW-icoon dat over de hoofdafbeelding wordt geplaatst.',
        'sort' => 1,
    ],
    [
        'key'    => 'image_editor.settings.general',
        'name'   => 'HW-icoon',
        'info'   => 'Het icoon dat automatisch over de hoofdafbeelding wordt geplaatst.',
        'sort'   => 1,
        'fields' => [
            [
                'name'  => 'hw_icon',
                'title' => 'HW icoon',
                'type'  => 'image',
                'info'  => 'Wordt linksonder over de hoofdafbeelding geplaatst (PNG met transparantie aanbevolen).',
            ],
        ],
    ],
];
