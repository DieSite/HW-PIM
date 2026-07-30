<?php

/*
|--------------------------------------------------------------------------
| Afwerkingsmogelijkheden bij maatwerkkleden
|--------------------------------------------------------------------------
|
| De tarieventabel uit de Asana-ticket "Bij maatwerk afwerkingsmoglijkheden
| toevoegen". Alle bedragen hieronder zijn **inkoop, ex BTW**, exact zoals ze
| in de ticket staan, zodat deze tabel er naast te leggen is. De omrekening
| naar een consumentenprijs (marge + BTW) gebeurt in
| App\Services\AfwerkingOptieService, zodat een marge-wijziging in de admin
| direct doorwerkt zonder deze file aan te raken.
|
| De shop rekent de uiteindelijke toeslag uit, omdat die pas bekend is als de
| klant zijn maten heeft ingevuld:
|
|     toeslag = omtrek_m × staffeltarief + Σ vaste toeslagen
|
| waarbij de omtrek 2 × (lengte + breedte) is voor een rechthoek en π × d voor
| een rond kleed. Een organisch gevormd kleed krijgt géén eigen omtrekformule:
| dat rekent met de omschrijvende rechthoek en krijgt het vaste bedrag er
| bovenop.
|
*/

return [
    /*
    | De merken waarvan de maatwerkkleden afwerkingen aangeboden krijgen.
    | `Mart Visser` bestaat in de productdata alleen als onderdeel van de
    | samengestelde string `Mart Visser|Karpi`; AfwerkingOptieService splitst
    | daarop, dus hier staat gewoon de losse merknaam.
    */
    'merken' => ['Eurogros', 'Karpi', 'Desso', 'Mart Visser'],

    /*
    | Fallback voor de admin-instelling general.afwerkingen.settings.marge_factor.
    | De inkooptarieven worden hiermee vermenigvuldigd voordat de BTW erbij komt.
    |
    | LET OP: dit getal is nog niet bevestigd door Hans/Jorik. Zolang de
    | shop-kant niet gebouwd is, ziet geen enkele klant er iets van.
    */
    'standaard_marge' => 2.0,

    /*
    | BTW-percentage dat over (inkoop × marge) heen gaat. De geëxporteerde
    | bedragen zijn inclusief BTW; de payload zegt dat ook expliciet met
    | `btw_inbegrepen`.
    */
    'btw_percentage' => 21,

    /*
    | De grens van de staffel "kleiner/groter dan 4 meter lengte", gemeten over
    | de langste zijde. De ticket laat precies 4,00 m ongedefinieerd; wij
    | rekenen die tot het lage tarief.
    */
    'staffelgrens_cm' => 400,

    'opties' => [
        'festonneren' => [
            'label'    => 'Festonneren',
            'eenheid'  => 'omtrek_m',
            'keuzes'   => [
                'standaard' => [
                    'label'    => 'Festonneren',
                    'tarieven' => [
                        ['max_lengte_cm' => 400, 'inkoop' => 4.50],
                        ['max_lengte_cm' => null, 'inkoop' => 5.25],
                    ],
                ],
            ],
            'toeslagen' => [
                [
                    'code'       => 'organisch',
                    'label'      => 'Organische vorm',
                    'type'       => 'vast',
                    'voorwaarde' => 'organische_vorm',
                    'inkoop'     => 15.00,
                ],
            ],
        ],

        'banderen' => [
            'label'   => 'Banderen',
            'eenheid' => 'omtrek_m',
            'keuzes'  => [
                'linnen_25_55' => [
                    'label'    => 'Linnen/katoen/jute band 2,5 t/m 5,5 cm zichtzijde',
                    'tarieven' => [['max_lengte_cm' => null, 'inkoop' => 12.50]],
                ],
                'linnen_55_90' => [
                    'label'    => 'Linnen/katoen/jute band 5,5 t/m 9 cm zichtzijde',
                    'tarieven' => [['max_lengte_cm' => null, 'inkoop' => 15.50]],
                ],
                'kunstleer_25_55' => [
                    'label'    => 'Kunstleer band 2,5 t/m 5,5 cm zichtzijde',
                    'tarieven' => [['max_lengte_cm' => null, 'inkoop' => 18.50]],
                ],
                'kunstleer_55_90' => [
                    'label'    => 'Kunstleer band 5,5 t/m 9 cm zichtzijde',
                    'tarieven' => [['max_lengte_cm' => null, 'inkoop' => 20.50]],
                ],
            ],
            'toeslagen' => [
                [
                    'code'       => 'organisch',
                    'label'      => 'Organische vorm',
                    'type'       => 'vast',
                    'voorwaarde' => 'organische_vorm',
                    'inkoop'     => 15.00,
                ],
            ],
        ],

        'banderen_blind' => [
            'label'   => 'Banderen blind',
            'eenheid' => 'omtrek_m',
            'keuzes'  => [
                'linnen_25_55' => [
                    'label'    => 'Linnen/katoen/jute 2,5 t/m 5,5 cm zichtzijde',
                    'tarieven' => [['max_lengte_cm' => null, 'inkoop' => 21.50]],
                ],
                'linnen_60_100' => [
                    'label'    => 'Linnen/katoen/jute 6 t/m 10 cm zichtzijde',
                    'tarieven' => [['max_lengte_cm' => null, 'inkoop' => 23.50]],
                ],
                'kunstleer_25_55' => [
                    'label'    => 'Kunstleer 2,5 t/m 5,5 cm zichtzijde',
                    'tarieven' => [['max_lengte_cm' => null, 'inkoop' => 23.50]],
                ],
                'kunstleer_60_100' => [
                    'label'    => 'Kunstleer 6 t/m 10 cm zichtzijde',
                    'tarieven' => [['max_lengte_cm' => null, 'inkoop' => 25.50]],
                ],
            ],
            'toeslagen' => [
                [
                    'code'       => 'organisch',
                    'label'      => 'Organische vorm',
                    'type'       => 'vast',
                    'voorwaarde' => 'organische_vorm',
                    'inkoop'     => 15.00,
                ],
            ],
        ],

        'volume' => [
            'label'   => 'Volume',
            'eenheid' => 'omtrek_m',
            /*
            | Het volumetarief is inclusief ondertapijt. Of dat de losse
            | "Met onderkleed"-variatiekeuze vervangt is nog een openstaande
            | vraag aan Hans/Jorik; de vlag staat in de payload zodat de shop
            | het kan afhandelen zonder PIM-wijziging.
            */
            'inclusief_onderkleed' => true,
            'keuzes'               => [
                'standaard' => [
                    'label'    => 'Volume (incl. ondertapijt)',
                    'tarieven' => [
                        ['max_lengte_cm' => 400, 'inkoop' => 16.00],
                        ['max_lengte_cm' => null, 'inkoop' => 17.50],
                    ],
                ],
            ],
            'toeslagen' => [
                [
                    'code'       => 'rond',
                    'label'      => 'Rond volumekleed',
                    'type'       => 'vast',
                    'voorwaarde' => 'ronde_vorm',
                    'inkoop'     => 25.00,
                ],
                [
                    'code'       => 'organisch',
                    'label'      => 'Organische vorm',
                    'type'       => 'vast',
                    'voorwaarde' => 'organische_vorm',
                    'inkoop'     => 50.00,
                ],
            ],
        ],

        'biesje' => [
            'label'   => 'Biesje',
            'eenheid' => 'omtrek_m',
            'keuzes'  => [
                'linnen_05' => [
                    'label'    => 'Linnen/katoen/jute 0,5 cm zichtzijde',
                    'tarieven' => [['max_lengte_cm' => null, 'inkoop' => 14.00]],
                ],
                'kunstleer_05' => [
                    'label'    => 'Kunstleer 0,5 cm zichtzijde',
                    'tarieven' => [['max_lengte_cm' => null, 'inkoop' => 15.75]],
                ],
            ],
            'toeslagen' => [],
        ],

        'anti_slip' => [
            'label' => 'Anti-slip',
            /*
            | De enige optie die per vierkante meter rekent in plaats van per
            | strekkende meter omtrek, en de enige die naast een randafwerking
            | gekozen mag worden.
            */
            'eenheid'       => 'm2',
            'combineerbaar' => true,
            'keuzes'        => [
                'standaard' => [
                    'label'    => 'Anti-slip (inclusief lijmen)',
                    'tarieven' => [['max_lengte_cm' => null, 'inkoop' => 10.00]],
                ],
            ],
            'toeslagen' => [],
        ],
    ],
];
