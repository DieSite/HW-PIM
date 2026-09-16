<?php

/*
|--------------------------------------------------------------------------
| AI-teksten – admin configuration tree
|--------------------------------------------------------------------------
|
| Merged into the "core" config (the admin Configuration screen) from
| App\Providers\AppServiceProvider. Only what a copywriter should be able to
| change without a deploy lives here; the mechanics stay in config/ai.php.
|
| Every text block (see ai.fields) gets its own section with the same fields.
| A block's tone of voice replaces the global one for that block only; its
| banned phrases and extra instructions come on top of the global ones.
|
*/

$blokken = [];
$sort = 2;

foreach (config('ai.fields', []) as $code => $field) {
    $blokken[] = [
        'key'    => "general.ai_texts.{$code}",
        'name'   => $field['label'],
        'info'   => "Alleen voor de tekst \"{$field['label']}\" (veldcode {$code}). Lege velden vallen terug op de algemene schrijfstijl. "
            .'Verwijs naar een ander blok met zijn veldcode, bijvoorbeeld "een samenvatting van beschrijving_l": '
            .'dat blok wordt dan eerst geschreven, of de huidige tekst ervan wordt meegegeven.',
        'sort'   => $sort++,
        'fields' => [
            [
                'name'  => 'instruction',
                'title' => 'Opdracht',
                'type'  => 'textarea',
                'info'  => 'Wat deze tekst moet bevatten. Laat leeg voor de ingebouwde opdracht: '
                    .(App\Services\AI\ProductDescriptionGenerator::FIELD_BRIEFS[$code] ?? '-'),
            ], [
                'name'  => 'tone_of_voice',
                'title' => 'Tone of voice',
                'type'  => 'textarea',
                'info'  => 'Vervangt de algemene tone of voice voor deze tekst. Laat leeg om de algemene te gebruiken.',
            ], [
                'name'  => 'banned_phrases',
                'title' => 'Verboden formuleringen',
                'type'  => 'textarea',
                'info'  => 'Eén formulering per regel. Komt bovenop de algemene lijst.',
            ], [
                'name'  => 'extra_instructions',
                'title' => 'Extra instructies',
                'type'  => 'textarea',
                'info'  => 'Komt bovenop de algemene extra instructies.',
            ],
        ],
    ];
}

return [
    [
        'key'  => 'general.ai_texts',
        'name' => 'AI-teksten',
        'info' => 'Instellingen voor het automatisch schrijven van productteksten.',
        'sort' => 7,
    ],
    [
        'key'    => 'general.ai_texts.style',
        'name'   => 'Algemene schrijfstijl',
        'info'   => 'De huisstijl die voor alle teksten aan het model wordt meegegeven.',
        'sort'   => 1,
        'fields' => [
            [
                'name'  => 'tone_of_voice',
                'title' => 'Tone of voice',
                'type'  => 'textarea',
                'info'  => 'Beschrijf hoe de teksten moeten klinken. Laat leeg voor de ingebouwde huisstijl.',
            ], [
                'name'  => 'banned_phrases',
                'title' => 'Verboden formuleringen',
                'type'  => 'textarea',
                'info'  => 'Eén formulering per regel. Komt bovenop de ingebouwde lijst.',
            ], [
                'name'  => 'extra_instructions',
                'title' => 'Extra instructies',
                'type'  => 'textarea',
                'info'  => 'Vrije tekst die onderaan de opdracht wordt toegevoegd, bijvoorbeeld een actie of een USP.',
            ],
        ],
    ],
    ...$blokken,
];
