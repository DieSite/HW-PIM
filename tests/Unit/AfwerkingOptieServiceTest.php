<?php

use App\Models\Product;
use App\Services\AfwerkingOptieService;
use Illuminate\Support\Facades\DB;

/**
 * Build an unsaved parent with its variants attached, so the service can be
 * exercised without provisioning attribute families for every case.
 *
 * @param  array<string, mixed>  $parentValues
 * @param  array<int, array<string, mixed>>  $variantValues
 */
function afwerkingParent(array $parentValues = [], array $variantValues = [[]]): Product
{
    $parent = new Product();
    $parent->values = ['common' => $parentValues];

    $variants = collect($variantValues)->map(function (array $values): Product {
        $variant = new Product();
        $variant->values = ['common' => array_merge(['maat' => 'Maatwerk'], $values)];

        return $variant;
    });

    $parent->setRelation('variants', $variants);

    return $parent;
}

/**
 * A parent of an allowed brand with one maatwerk variant — the happy path.
 *
 * @param  array<string, mixed>  $parentValues
 */
function eurogrosMaatwerkParent(array $parentValues = []): Product
{
    return afwerkingParent(array_merge(['merk' => 'Eurogros'], $parentValues));
}

function stelAfwerkingConfigIn(string $code, string $value): void
{
    DB::table('core_config')->updateOrInsert(
        ['code' => $code, 'channel_code' => null, 'locale_code' => null],
        ['value' => $value, 'created_at' => now(), 'updated_at' => now()]
    );
}

function afwerkingService(): AfwerkingOptieService
{
    return app(AfwerkingOptieService::class);
}

/**
 * A Desso maatwerk parent with a plain and a "Met onderkleed" variant, the way
 * the catalogue stores them.
 *
 * @param  array<string, mixed>  $parentValues
 */
function dessoMaatwerkParent(array $parentValues = []): Product
{
    return afwerkingParent(
        array_merge(['merk' => 'Desso', 'collectie' => 'Desso & Ex', 'productnaam' => 'Desso & Ex 4311-618'], $parentValues),
        [
            ['onderkleed' => 'Zonder onderkleed', 'prijs' => ['EUR' => 145]],
            ['onderkleed' => 'Met onderkleed', 'prijs' => ['EUR' => '156']],
        ]
    );
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function randafwerking(array $payload): array
{
    expect($payload['opties'])->toHaveCount(1);

    return $payload['opties'][0];
}

/**
 * @param  array<string, mixed>  $optie
 * @return array<string, list<float>>
 */
function tarievenPerKeuze(array $optie): array
{
    return collect($optie['keuzes'])->mapWithKeys(fn (array $keuze): array => [
        $keuze['code'] => array_column($keuze['tarieven'], 'tarief'),
    ])->all();
}

it('levert payloadversie 2 met verkoopprijzen incl. BTW', function () {
    $payload = afwerkingService()->payloadVoor(eurogrosMaatwerkParent());

    expect($payload['versie'])->toBe(2)
        ->and($payload['valuta'])->toBe('EUR')
        ->and($payload['btw_inbegrepen'])->toBeTrue();
});

it('laat Eurogros kiezen tussen feston en band suède, zonder meerprijs', function () {
    $optie = randafwerking(afwerkingService()->payloadVoor(eurogrosMaatwerkParent()));

    expect($optie['code'])->toBe('randafwerking')
        ->and($optie['verplicht'])->toBeTrue()
        ->and($optie['eenheid'])->toBe('omtrek_m')
        ->and(array_column($optie['keuzes'], 'label'))->toBe(['Feston', 'Band suède'])
        ->and(tarievenPerKeuze($optie))->toBe(['feston' => [0.0], 'band_suede' => [0.0]])
        ->and($optie['toeslagen'])->toBe([]);
});

it('laat bij Desso festonneren gratis en rekent banderen als verschil uit de prijslijst', function () {
    $optie = randafwerking(afwerkingService()->payloadVoor(dessoMaatwerkParent()));

    expect($optie['eenheid'])->toBe('m2')
        ->and($optie['verplicht'])->toBeTrue()
        ->and($optie)->not->toHaveKeys(['minimum_m2', 'referentie_m2_prijs', 'prijsmodel'])
        ->and(tarievenPerKeuze($optie))->toBe([
            'festonneren'    => [0.0, 0.0, 0.0],
            'blind_banderen' => [55.0, 49.0, 43.0],
            'linnen_band'    => [55.0, 49.0, 43.0],
        ])
        ->and(array_column($optie['keuzes'][1]['tarieven'], 'max_m2'))->toBe([4.0, 5.9999, null]);
});

it('kiest de Desso-kwaliteit op collectie en productnaam', function (array $waarden, array $banderen) {
    $optie = randafwerking(afwerkingService()->payloadVoor(dessoMaatwerkParent($waarden)));

    expect(tarievenPerKeuze($optie)['blind_banderen'])->toBe($banderen);
})->with([
    'Desso&Ex Nature'  => [['collectie' => 'Desso & Ex Nature'], [55.0, 49.0, 43.0]],
    'Sense of Marble'  => [['collectie' => 'Sense of Marble', 'productnaam' => 'Sense of Marble 1103'], [55.0, 49.0, 43.0]],
    'Canvas Botanical' => [['collectie' => 'Desso Weave', 'productnaam' => 'Desso Botanical 1320'], [54.0, 50.0, 45.0]],
    'Canvas Weave'     => [['collectie' => 'Desso Weave', 'productnaam' => 'Desso Weave 1318'], [53.0, 50.0, 45.0]],
    'Vintage'          => [['collectie' => 'Bonaparte Vintage', 'productnaam' => 'Vintage 118.202'], [54.0, 50.0, 45.0]],
]);

it('biedt geen keuze aan bij een Desso-kwaliteit die niet op de prijslijst staat', function () {
    $parent = dessoMaatwerkParent(['collectie' => 'Art Deco', 'productnaam' => 'Art Deco 2022']);

    expect(afwerkingService()->isBeschikbaar($parent))->toBeFalse()
        ->and(afwerkingService()->payloadVoor($parent))->toBeNull();
});

it('noemt het XS-garen bij festonneren van Sense of Marble', function () {
    $optie = randafwerking(afwerkingService()->payloadVoor(dessoMaatwerkParent(['collectie' => 'Sense of Marble'])));

    expect($optie['keuzes'][0]['label'])->toBe('Festonneren (XS garen)');
});

it('geeft null als de feature is uitgeschakeld', function () {
    stelAfwerkingConfigIn('general.afwerkingen.settings.enabled', '0');

    expect(afwerkingService()->payloadVoor(eurogrosMaatwerkParent()))->toBeNull()
        ->and(afwerkingService()->isBeschikbaar(eurogrosMaatwerkParent()))->toBeFalse();
});

it('laat een merk uitzetten in de admin', function () {
    stelAfwerkingConfigIn('general.afwerkingen.merken.desso_enabled', '0');

    expect(afwerkingService()->isBeschikbaar(dessoMaatwerkParent()))->toBeFalse()
        ->and(afwerkingService()->isBeschikbaar(eurogrosMaatwerkParent()))->toBeTrue();
});

it('biedt randafwerkingen aan bij Eurogros en Desso', function (Product $parent) {
    expect(afwerkingService()->isBeschikbaar($parent))->toBeTrue();
})->with([
    'Eurogros' => fn (): Product => eurogrosMaatwerkParent(),
    'Desso'    => fn (): Product => dessoMaatwerkParent(),
]);

it('biedt geen keuze aan bij merken zonder regels', function (string $merk) {
    expect(afwerkingService()->isBeschikbaar(afwerkingParent(['merk' => $merk])))->toBeFalse();
})->with(['Karpi', 'Mart Visser|Karpi', 'De Munk', 'Louis De Poortere', '']);

it('valt terug op het merk van een variant als de parent er geen heeft', function () {
    $parent = afwerkingParent([], [['merk' => 'Eurogros']]);

    expect(afwerkingService()->isBeschikbaar($parent))->toBeTrue();
});

it('slaat parents zonder maatwerk-variant over', function () {
    $parent = afwerkingParent(['merk' => 'Eurogros'], [['maat' => '200 cm x 300 cm']]);

    expect(afwerkingService()->isBeschikbaar($parent))->toBeFalse();
});

it('laat de handmatige override "nee" winnen van een merk met regels', function () {
    $parent = eurogrosMaatwerkParent(['afwerking_beschikbaar' => 'nee']);

    expect(afwerkingService()->isBeschikbaar($parent))->toBeFalse()
        ->and(afwerkingService()->payloadVoor($parent))->toBeNull();
});

it('laat de override "ja" een kleed zonder maatwerk-variant aanzetten', function () {
    $parent = afwerkingParent(['merk' => 'Eurogros', 'afwerking_beschikbaar' => 'ja'], [['maat' => '200 cm x 300 cm']]);

    expect(afwerkingService()->isBeschikbaar($parent))->toBeTrue();
});

it('kan met de override "ja" geen prijs verzinnen voor een merk zonder regels', function () {
    $parent = afwerkingParent(['merk' => 'De Munk', 'afwerking_beschikbaar' => 'ja']);

    expect(afwerkingService()->isBeschikbaar($parent))->toBeFalse();
});

it('behandelt een lege override als automatisch', function () {
    expect(afwerkingService()->isBeschikbaar(eurogrosMaatwerkParent(['afwerking_beschikbaar' => ''])))->toBeTrue()
        ->and(afwerkingService()->isBeschikbaar(afwerkingParent(['merk' => 'De Munk', 'afwerking_beschikbaar' => ''])))->toBeFalse();
});

it('leest de payload ook uit de exporter-array', function () {
    $item = [
        'values'   => ['common' => ['merk' => 'Desso', 'collectie' => 'Desso & Ex']],
        'variants' => [
            ['values' => json_encode(['common' => ['maat' => 'Maatwerk', 'prijs' => ['EUR' => 145]]])],
        ],
    ];

    $optie = randafwerking(afwerkingService()->payloadVoorItem($item));

    expect($optie['eenheid'])->toBe('m2')
        ->and(tarievenPerKeuze($optie)['blind_banderen'])->toBe([55.0, 49.0, 43.0]);
});

it('biedt nooit afwerkingen aan bij een wollen kleed', function (string $materiaal) {
    $parent = eurogrosMaatwerkParent(['materiaal' => $materiaal]);

    expect(afwerkingService()->isBeschikbaar($parent))->toBeFalse()
        ->and(afwerkingService()->payloadVoor($parent))->toBeNull();
})->with(['Wol', ' Wol', 'Wol|Polyester', 'Katoen|Wol|Polyester', 'Wol| Overig', 'Wol en katoen']);

it('biedt afwerkingen aan bij kleden zonder wol', function (string $materiaal) {
    expect(afwerkingService()->isBeschikbaar(eurogrosMaatwerkParent(['materiaal' => $materiaal])))->toBeTrue();
})->with(['Polyester', 'Polyester|Katoen|Acryl', 'Gerecycled PET', '']);

it('kijkt voor het materiaal ook naar de varianten', function () {
    $parent = afwerkingParent(['merk' => 'Eurogros'], [['materiaal' => 'Wol']]);

    expect(afwerkingService()->isBeschikbaar($parent))->toBeFalse();
});

it('laat de override "ja" niet winnen van een wollen kleed', function () {
    $parent = eurogrosMaatwerkParent(['materiaal' => 'Wol', 'afwerking_beschikbaar' => 'ja']);

    expect(afwerkingService()->isBeschikbaar($parent))->toBeFalse();
});
