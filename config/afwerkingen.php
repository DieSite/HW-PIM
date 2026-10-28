<?php

/*
|--------------------------------------------------------------------------
| Randafwerkingen bij maatwerkkleden
|--------------------------------------------------------------------------
|
| Per merk, uit de Asana-ticket "Randafwerkingen maatwerk" (oktober 2026):
|
|  - Karpi / Mart Visser: geen keuze, het kleed wordt afgewerkt zoals op het
|    staal (meestal volume). Daarom staan ze hier niet.
|  - Louis De Poortere: bestaat geen maatwerk van.
|  - Eurogros (Meet & Maak, Arold, Montgomery): keuze tussen feston en band
|    suède, zonder meerprijs. De wollen kwaliteiten (Cambell, Kapiti, Ulva…)
|    krijgen de standaard wollen afwerking en vallen af op materiaal.
|  - Desso: de klant kiest festonneren of blind banderen / linnen band. Onze
|    m²-prijs is die van een gefestonneerd kleed en blijft altijd gelijk;
|    banderen kost per m² het verschil dat de adviesverkoopprijslijst van
|    Desso (1 oktober 2025, pagina 2 en 3) tussen die twee afwerkingen
|    rekent, per kwaliteit en per oppervlakteband.
|
| Alle bedragen zijn verkoopprijzen website, incl. BTW. De shop rekent, want
| de toeslag hangt af van de maten die de klant invult. De basisprijs van het
| kleed verandert nooit:
|
|     prijs = m² × variantprijs + hoeveelheid × tarief
|
| met als hoeveelheid de omtrek in meters (`omtrek_m`) of de oppervlakte (`m2`).
| Elke merkregel levert één verplichte keuze: de eerste keuze is de standaard.
|
*/

return [
    /*
    | Materialen waarbij een kleed nooit een afwerkingskeuze krijgt: niet bij
    | een merk met regels en ook niet met de handmatige override "ja".
    | `materiaal` is een `|`-lijst ("Wol|Polyester"); een kleed valt af zodra
    | één onderdeel het woord bevat, dus ook "Wol en katoen".
    */
    'uitgesloten_materialen' => ['Wol'],

    /*
    | Per merk de kwaliteiten met hun keuzes. Een kwaliteit zonder `match`
    | geldt voor elk maatwerkkleed van het merk; anders moet het kleed aan
    | alle voorwaarden voldoen (`collectie` exact, `productnaam_begint_met`
    | als voorvoegsel, beide hoofdletterongevoelig). Een kleed van een merk
    | met regels dat geen kwaliteit vindt, krijgt geen keuze: liever niets
    | aanbieden dan een verkeerde prijs.
    |
    | Mart Visser bestaat in de productdata alleen als `Mart Visser|Karpi`;
    | het merk wordt op `|` gesplitst, dus een regel voor "Karpi" zou daar ook
    | gelden. Die is er bewust niet.
    */
    'merken' => [
        'Eurogros' => [
            'eenheid'     => 'omtrek_m',
            'kwaliteiten' => [
                'eurogros' => [
                    'keuzes' => [
                        'feston'     => ['label' => 'Feston', 'tarieven' => [['tarief' => 0.0]]],
                        'band_suede' => ['label' => 'Band suède', 'tarieven' => [['tarief' => 0.0]]],
                    ],
                ],
            ],
        ],

        /*
        | Hieronder staan de m²-prijzen letterlijk uit de prijslijst; de toeslag
        | per band is `m2` van de keuze min `m2` van de `basiskeuze`
        | (festonneren), dus voor Desso&Ex 55 / 49 / 43 per m² voor banderen.
        |
        | De prijslijst rekent in drie banden: ≤ 4 m², 4–6 m² en ≥ 6 m². Met
        | maten in hele centimeters is een oppervlakte altijd een veelvoud van
        | 0,0001 m², dus "4–6 m²" is tot en met 5,9999 m²: een kleed van precies
        | 200 × 300 cm valt in de band van 6 m² en meer, zoals Desso bedoelt.
        |
        | Volume biedt Desso bij geen van de kwaliteiten die wij voeren aan.
        |
        | Art Deco, Denim, Fresco en Mozaic (68 parents) staan nog niet op de
        | lijst: welke kwaliteit van de prijslijst dat zijn, is niet zeker.
        */
        'Desso' => [
            'eenheid'     => 'm2',
            'basiskeuze'  => 'festonneren',
            'kwaliteiten' => [
                'desso_ex' => [
                    'label'  => 'Desso&Ex / Desso&Ex Nature',
                    'match'  => ['collectie' => ['Desso & Ex', 'Desso & Ex Nature']],
                    'keuzes' => [
                        'festonneren'    => ['label' => 'Festonneren', 'm2' => [164.95, 148.95, 133.95]],
                        'blind_banderen' => ['label' => 'Blind banderen', 'm2' => [219.95, 197.95, 176.95]],
                        'linnen_band'    => ['label' => 'Linnen band', 'm2' => [219.95, 197.95, 176.95]],
                    ],
                ],
                'sense_of_marble' => [
                    'label'  => 'Sense of Marble',
                    'match'  => ['collectie' => ['Sense of Marble']],
                    'keuzes' => [
                        'festonneren'    => ['label' => 'Festonneren (XS garen)', 'm2' => [164.95, 148.95, 133.95]],
                        'blind_banderen' => ['label' => 'Blind banderen', 'm2' => [219.95, 197.95, 176.95]],
                        'linnen_band'    => ['label' => 'Linnen band', 'm2' => [219.95, 197.95, 176.95]],
                    ],
                ],
                'canvas_botanical' => [
                    'label'  => 'Canvas Botanical',
                    'match'  => ['collectie' => ['Desso Weave'], 'productnaam_begint_met' => ['Desso Botanical']],
                    'keuzes' => [
                        'festonneren'    => ['label' => 'Festonneren', 'm2' => [154.95, 136.95, 123.95]],
                        'blind_banderen' => ['label' => 'Blind banderen', 'm2' => [208.95, 186.95, 168.95]],
                        'linnen_band'    => ['label' => 'Linnen band', 'm2' => [208.95, 186.95, 168.95]],
                    ],
                ],
                'canvas_weave' => [
                    'label'  => 'Canvas Weave',
                    'match'  => ['collectie' => ['Desso Weave'], 'productnaam_begint_met' => ['Desso Weave']],
                    'keuzes' => [
                        'festonneren'    => ['label' => 'Festonneren', 'm2' => [152.95, 134.95, 121.95]],
                        'blind_banderen' => ['label' => 'Blind banderen', 'm2' => [205.95, 184.95, 166.95]],
                        'linnen_band'    => ['label' => 'Linnen band', 'm2' => [205.95, 184.95, 166.95]],
                    ],
                ],
                'vintage' => [
                    'label'  => 'Bonaparte Vintage',
                    'match'  => ['collectie' => ['Bonaparte Vintage']],
                    'keuzes' => [
                        'festonneren'    => ['label' => 'Festonneren', 'm2' => [161.95, 144.95, 129.95]],
                        'blind_banderen' => ['label' => 'Blind banderen', 'm2' => [215.95, 194.95, 174.95]],
                        'linnen_band'    => ['label' => 'Linnen band', 'm2' => [215.95, 194.95, 174.95]],
                    ],
                ],
            ],
        ],
    ],

    /*
    | De bovengrenzen van de oppervlaktebanden bij de drie `m2`-prijzen van een
    | Desso-keuze, in dezelfde volgorde. null = open band.
    */
    'm2_banden' => [4.0, 5.9999, null],
];
