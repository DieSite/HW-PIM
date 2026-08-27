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
        'key'    => 'general.ai_texts.settings',
        'name'   => 'Model',
        'info'   => 'Welke aanbieder en welk model de teksten schrijft.',
        'sort'   => 1,
        'fields' => [
            [
                'name'          => 'enabled',
                'title'         => 'AI-teksten ingeschakeld',
                'type'          => 'boolean',
                'info'          => 'Zet de generatieknoppen in de admin aan of uit.',
                'default_value' => 1,
            ], [
                'name'    => 'driver',
                'title'   => 'Aanbieder',
                'type'    => 'select',
                'options' => [
                    ['title' => 'Google Gemini (standaard)', 'value' => 'gemini'],
                    ['title' => 'OpenAI', 'value' => 'openai'],
                ],
                'info' => 'Laat leeg om de standaard uit config/ai.php te gebruiken.',
            ], [
                'name'  => 'model',
                'title' => 'Model',
                'type'  => 'text',
                'info'  => 'Bijvoorbeeld gemini-3.7-flash. Laat leeg voor de standaard van de gekozen aanbieder.',
            ], [
                'name'  => 'api_key',
                'title' => 'API-sleutel',
                'type'  => 'password',
                'info'  => 'Laat leeg om de sleutel uit de .env te blijven gebruiken.',
            ],
        ],
    ],
    [
        'key'    => 'general.ai_texts.style',
        'name'   => 'Schrijfstijl',
        'info'   => 'De huisstijl die in elke opdracht aan het model wordt meegegeven.',
        'sort'   => 2,
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
