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
*/

return [
    [
        'key'  => 'general.ai_texts',
        'name' => 'AI-teksten',
        'info' => 'Instellingen voor het automatisch schrijven van productteksten.',
        'sort' => 7,
    ],
    [
        'key'    => 'general.ai_texts.style',
        'name'   => 'Schrijfstijl',
        'info'   => 'De huisstijl die in elke opdracht aan het model wordt meegegeven.',
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
];
