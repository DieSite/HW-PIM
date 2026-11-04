<?php

/*
|--------------------------------------------------------------------------
| Product edit screen: field sections
|--------------------------------------------------------------------------
|
| The attribute family puts nearly every field in one "General" group. The
| edit screen regroups them into these sections, in this order, each with
| its own card and a link in the jump menu. Fields keep their order within
| a section as listed here.
|
| A field that is in no section still shows, in a card named after its
| attribute group, so a new attribute never silently disappears. The
| `collapsed` section is folded away under "Meer velden": still part of the
| form and saved as usual, just out of sight.
|
*/

return [
    'sections' => [
        'basis' => [
            'label'      => 'Basis',
            'attributes' => ['sku', 'ean', 'productnaam', 'merk', 'collectie', 'kwaliteit', 'in_collectie', 'onderkleed', 'maat', 'maatgroep'],
        ],

        'afbeeldingen' => [
            'label'      => 'Afbeeldingen',
            'attributes' => ['afbeelding', 'afbeelding_zonder_logo'],
        ],

        'prijzen' => [
            'label'      => 'Prijzen',
            'attributes' => ['prijs', 'adviesverkoopprijs', 'extra_korting', 'sale_prijs', 'minimale_prijs'],
        ],

        'voorraad' => [
            'label'      => 'Voorraad & levertijd',
            'attributes' => [
                'voorraad_hw_5_korting',
                'voorraad_eurogros',
                'voorraad_5_korting_handmatig',
                'uitverkoop_15_korting',
                'levertijd',
                'levertijd_voorradig',
                'levertijd_niet_voorradig',
            ],
        ],

        'eigenschappen' => [
            'label'      => 'Eigenschappen',
            'attributes' => [
                'materiaal',
                'loopvlak',
                'poolhoogte',
                'productie_techniek',
                'randafwerking',
                'afwerking',
                'vorm',
                'kleuren',
                'patroon',
                'gewicht',
                'gebruik',
                'productieland',
                'garantie',
                'onderhoudsadvies',
            ],
        ],

        'maatwerk' => [
            'label'      => 'Maatwerk',
            'attributes' => [
                'prijs_per_m2',
                'sale_prijs_per_m2',
                'prijs_rond_m2',
                'afwerking_beschikbaar',
                'festoneren_banderen',
                'maximale_breedte',
                'maximale_breedte_cm',
                'maximale_lengte',
                'maximale_lengte_cm',
                'maximale_diameter',
                'maximale_diameter_cm',
            ],
        ],

        'teksten' => [
            'label'      => 'Teksten & SEO',
            'attributes' => ['beschrijving_l', 'beschrijving_k', 'meta_titel', 'meta_beschrijving'],
        ],
    ],

    /*
     * Rarely touched or generated fields, folded away under "Meer velden".
     */
    'collapsed' => [
        'label'      => 'Meer velden',
        'attributes' => ['sorteer_volgorde', 'dob', 'prijs2', 'voorraad_5_korting'],
    ],
];
