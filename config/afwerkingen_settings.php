<?php

/*
|--------------------------------------------------------------------------
| Afwerkingsmogelijkheden – admin configuration tree
|--------------------------------------------------------------------------
|
| Merged into the "core" config (the admin Configuration screen) from
| App\Providers\AppServiceProvider. De tarieventabel zelf staat in
| config/afwerkingen.php; alleen de admin-instelbare velden horen hier.
|
| Per afwerkingstype staan er twee velden: een aan/uit-schakelaar en een
| merkenlijst. Zo kan een heel type uitgezet worden, of beperkt worden tot een
| groep merken, zonder code te wijzigen. Een lege merkenlijst betekent "geen
| extra beperking" — dan gelden de merken uit config/afwerkingen.php.
|
| LET OP: het multiselect-veldtype slaat op als komma-gescheiden string (zie
| packages/Webkul/Admin/src/Resources/views/configuration/field-type.blade.php),
| dus AfwerkingOptieService splitst de waarde zelf.
|
*/

$merkOpties = array_map(
    fn (string $merk): array => ['title' => $merk, 'value' => $merk],
    config('afwerkingen.merken', [])
);

$typeVelden = [];

foreach (config('afwerkingen.opties', []) as $code => $optie) {
    $typeVelden[] = [
        'name'          => $code.'_enabled',
        'title'         => $optie['label'].' aanbieden',
        'type'          => 'boolean',
        'default_value' => 1,
    ];

    $typeVelden[] = [
        'name'    => $code.'_merken',
        'title'   => $optie['label'].' – alleen voor deze merken',
        'type'    => 'multiselect',
        'options' => $merkOpties,
        'info'    => 'Laat leeg om geen extra beperking op te leggen.',
    ];
}

return [
    [
        'key'  => 'general.afwerkingen',
        'name' => 'Afwerkingen',
        'info' => 'Afwerkingsmogelijkheden bij maatwerkkleden',
        'sort' => 4,
    ],
    [
        'key'    => 'general.afwerkingen.settings',
        'name'   => 'Algemeen',
        'info'   => 'Of afwerkingen aangeboden worden en tegen welke opslag op de inkoopprijs',
        'sort'   => 1,
        'fields' => [
            [
                'name'          => 'enabled',
                'title'         => 'Afwerkingen ingeschakeld',
                'type'          => 'boolean',
                'info'          => 'Zet het aanbieden van afwerkingen bij maatwerkkleden aan of uit.',
                'default_value' => 1,
            ], [
                'name'          => 'marge_factor',
                'title'         => 'Marge op inkoopprijs (factor, dus 2 = verdubbeling)',
                'type'          => 'number',
                'info'          => 'De inkooptarieven worden hiermee vermenigvuldigd; daarna komt de BTW erover.',
                'default_value' => config('afwerkingen.standaard_marge'),
            ],
        ],
    ],
    [
        'key'    => 'general.afwerkingen.types',
        'name'   => 'Per afwerking',
        'info'   => 'Zet losse afwerkingen uit of beperk ze tot bepaalde merken',
        'sort'   => 2,
        'fields' => $typeVelden,
    ],
];
