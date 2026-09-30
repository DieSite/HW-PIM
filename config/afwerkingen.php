<?php

/*
|--------------------------------------------------------------------------
| Afwerkingsmogelijkheden bij maatwerkkleden
|--------------------------------------------------------------------------
|
| De tarieventabel uit de Asana-ticket "Bij maatwerk afwerkingsmoglijkheden
| toevoegen". Alle bedragen hieronder zijn **verkoopprijzen website, incl.
| BTW**, exact zoals ze in de ticket staan, zodat deze tabel er naast te
| leggen is. Ze gaan ongewijzigd naar de shop; er zit geen marge- of
| BTW-omrekening meer tussen.
|
| De shop rekent de uiteindelijke toeslag uit, omdat die pas bekend is als de
| klant zijn maten heeft ingevuld:
|
|     toeslag = omtrek_m × tarief + Σ vaste toeslagen
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
    | daarop, dus hier staat gewoon de losse merknaam. De Munk ontbreekt
    | bewust: maatwerk van De Munk heeft zijn afwerkingen al.
    */
    'merken' => ['Eurogros', 'Karpi', 'Desso', 'Mart Visser'],

    /*
    | De ticket gaf voor festonneren en volume eerst een tarief onder en boven
    | de 4 meter lengte; die staffel is vervallen en vervangen door het bedrag
    | er precies tussenin (9,00/10,50 → 9,75 en 32,00/35,00 → 33,50).
    */
    'opties' => [
        'festonneren' => [
            'label'    => 'Festonneren',
            'eenheid'  => 'omtrek_m',
            'keuzes'   => [
                'standaard' => [
                    'label'    => 'Festonneren',
                    'tarieven' => [['max_lengte_cm' => null, 'prijs' => 9.75]],
                ],
            ],
            'toeslagen' => [
                [
                    'code'       => 'organisch',
                    'label'      => 'Organische vorm',
                    'type'       => 'vast',
                    'voorwaarde' => 'organische_vorm',
                    'prijs'      => 30.00,
                ],
            ],
        ],

        'banderen' => [
            'label'   => 'Banderen',
            'eenheid' => 'omtrek_m',
            'keuzes'  => [
                'linnen_25_55' => [
                    'label'    => 'Linnen/katoen/jute band 2,5 t/m 5,5 cm zichtzijde',
                    'tarieven' => [['max_lengte_cm' => null, 'prijs' => 25.00]],
                ],
                'linnen_55_90' => [
                    'label'    => 'Linnen/katoen/jute band 5,5 t/m 9 cm zichtzijde',
                    'tarieven' => [['max_lengte_cm' => null, 'prijs' => 31.00]],
                ],
                'kunstleer_25_55' => [
                    'label'    => 'Kunstleer band 2,5 t/m 5,5 cm zichtzijde',
                    'tarieven' => [['max_lengte_cm' => null, 'prijs' => 37.00]],
                ],
                'kunstleer_55_90' => [
                    'label'    => 'Kunstleer band 5,5 t/m 9 cm zichtzijde',
                    'tarieven' => [['max_lengte_cm' => null, 'prijs' => 41.00]],
                ],
            ],
            'toeslagen' => [
                [
                    'code'       => 'organisch',
                    'label'      => 'Organische vorm',
                    'type'       => 'vast',
                    'voorwaarde' => 'organische_vorm',
                    'prijs'      => 30.00,
                ],
            ],
        ],

        'banderen_blind' => [
            'label'   => 'Banderen blind',
            'eenheid' => 'omtrek_m',
            'keuzes'  => [
                'linnen_25_55' => [
                    'label'    => 'Linnen/katoen/jute 2,5 t/m 5,5 cm zichtzijde',
                    'tarieven' => [['max_lengte_cm' => null, 'prijs' => 43.00]],
                ],
                'linnen_60_100' => [
                    'label'    => 'Linnen/katoen/jute 6 t/m 10 cm zichtzijde',
                    'tarieven' => [['max_lengte_cm' => null, 'prijs' => 47.00]],
                ],
                'kunstleer_25_55' => [
                    'label'    => 'Kunstleer 2,5 t/m 5,5 cm zichtzijde',
                    'tarieven' => [['max_lengte_cm' => null, 'prijs' => 47.00]],
                ],
                'kunstleer_60_100' => [
                    'label'    => 'Kunstleer 6 t/m 10 cm zichtzijde',
                    'tarieven' => [['max_lengte_cm' => null, 'prijs' => 51.00]],
                ],
            ],
            'toeslagen' => [
                [
                    'code'       => 'organisch',
                    'label'      => 'Organische vorm',
                    'type'       => 'vast',
                    'voorwaarde' => 'organische_vorm',
                    'prijs'      => 30.00,
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
                    'tarieven' => [['max_lengte_cm' => null, 'prijs' => 33.50]],
                ],
            ],
            'toeslagen' => [
                [
                    'code'       => 'rond',
                    'label'      => 'Rond volumekleed',
                    'type'       => 'vast',
                    'voorwaarde' => 'ronde_vorm',
                    'prijs'      => 50.00,
                ],
                [
                    'code'       => 'organisch',
                    'label'      => 'Organische vorm',
                    'type'       => 'vast',
                    'voorwaarde' => 'organische_vorm',
                    'prijs'      => 100.00,
                ],
            ],
        ],

        'biesje' => [
            'label'   => 'Biesje',
            'eenheid' => 'omtrek_m',
            'keuzes'  => [
                'linnen_05' => [
                    'label'    => 'Linnen/katoen/jute 0,5 cm zichtzijde',
                    'tarieven' => [['max_lengte_cm' => null, 'prijs' => 28.00]],
                ],
                'kunstleer_05' => [
                    'label'    => 'Kunstleer 0,5 cm zichtzijde',
                    'tarieven' => [['max_lengte_cm' => null, 'prijs' => 31.50]],
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
                    'tarieven' => [['max_lengte_cm' => null, 'prijs' => 20.00]],
                ],
            ],
            'toeslagen' => [],
        ],
    ],
];
