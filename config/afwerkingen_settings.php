<?php

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Afwerkingsmogelijkheden – admin configuration tree
|--------------------------------------------------------------------------
|
| Merged into the "core" config (the admin Configuration screen) from
| App\Providers\AppServiceProvider. De regels en prijzen zelf staan in
| config/afwerkingen.php; alleen de admin-instelbare velden horen hier.
|
| Per merk met regels staat er een aan/uit-schakelaar, zodat een merk uitgezet
| kan worden zonder code te wijzigen. De sleutel is de merknaam als slug
| ("Eurogros" → `eurogros_enabled`), zie AfwerkingOptieService.
|
*/

$merkVelden = [];

foreach (array_keys(config('afwerkingen.merken', [])) as $merk) {
    $merkVelden[] = [
        'name'          => Str::slug($merk, '_').'_enabled',
        'title'         => "Randafwerkingen voor {$merk} aanbieden",
        'type'          => 'boolean',
        'default_value' => 1,
    ];
}

return [
    [
        'key'  => 'general.afwerkingen',
        'name' => 'Afwerkingen',
        'info' => 'Randafwerkingen bij maatwerkkleden',
        'sort' => 4,
    ],
    [
        'key'    => 'general.afwerkingen.settings',
        'name'   => 'Algemeen',
        'info'   => 'Of randafwerkingen aangeboden worden',
        'sort'   => 1,
        'fields' => [
            [
                'name'          => 'enabled',
                'title'         => 'Afwerkingen ingeschakeld',
                'type'          => 'boolean',
                'info'          => 'Zet het aanbieden van randafwerkingen bij maatwerkkleden aan of uit.',
                'default_value' => 1,
            ],
        ],
    ],
    [
        'key'    => 'general.afwerkingen.merken',
        'name'   => 'Per merk',
        'info'   => 'Zet de randafwerkingen van een merk uit',
        'sort'   => 2,
        'fields' => $merkVelden,
    ],
];
