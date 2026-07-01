<?php

/*
|--------------------------------------------------------------------------
| Competitor pricing – admin configuration tree
|--------------------------------------------------------------------------
|
| Merged into the "core" config (the admin Configuration screen) from
| App\Providers\AppServiceProvider. The runtime pipeline defaults/paths live
| in config/competitor_pricing.php; only the admin-editable fields belong here.
|
*/

return [
    [
        'key'  => 'general.pricing',
        'name' => 'Prijsstelling',
        'info' => 'Concurrentie-gebaseerde prijsstelling',
        'sort' => 3,
    ],
    [
        'key'    => 'general.pricing.settings',
        'name'   => 'Prijsstelling',
        'info'   => 'Instellingen voor de dynamische prijsberekening t.o.v. concurrenten',
        'sort'   => 1,
        'fields' => [
            [
                'name'  => 'enabled',
                'title' => 'Concurrentie-analyse ingeschakeld',
                'type'  => 'boolean',
                'info'  => 'Zet de dagelijkse concurrentie-analyse en dynamische prijsberekening aan of uit.',
            ], [
                'name'  => 'max_kortingspercentage',
                'title' => 'Max. korting t.o.v. adviesprijs (in %, dus voor 25% vul in 25)',
                'type'  => 'number',
            ],
        ],
    ],
];
