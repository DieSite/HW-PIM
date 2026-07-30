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
    $parent = new Product;
    $parent->values = ['common' => $parentValues];

    $variants = collect($variantValues)->map(function (array $values): Product {
        $variant = new Product;
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
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>|null
 */
function optieUitPayload(array $payload, string $code): ?array
{
    return collect($payload['opties'])->firstWhere('code', $code);
}

beforeEach(function () {
    config()->set('afwerkingen.standaard_marge', 2.0);
    stelAfwerkingConfigIn('general.afwerkingen.settings.marge_factor', '2');
});

it('rekent het inkooptarief om naar een consumentenprijs met marge en BTW', function () {
    $payload = afwerkingService()->payloadVoor(eurogrosMaatwerkParent());

    // 4,50 inkoop x 2 marge x 1,21 BTW = 10,89
    expect($payload['opties'])->not->toBeEmpty()
        ->and(optieUitPayload($payload, 'festonneren')['keuzes'][0]['tarieven'][0]['tarief'])->toBe(10.89)
        ->and($payload['btw_inbegrepen'])->toBeTrue();
});

it('volgt de marge-instelling uit de admin', function () {
    stelAfwerkingConfigIn('general.afwerkingen.settings.marge_factor', '1');

    $payload = afwerkingService()->payloadVoor(eurogrosMaatwerkParent());

    // 4,50 x 1 x 1,21 = 5,45 (afgerond)
    expect(optieUitPayload($payload, 'festonneren')['keuzes'][0]['tarieven'][0]['tarief'])->toBe(5.45);
});

it('houdt de staffelgrens van 4 meter aan', function () {
    $payload = afwerkingService()->payloadVoor(eurogrosMaatwerkParent());
    $tarieven = optieUitPayload($payload, 'festonneren')['keuzes'][0]['tarieven'];

    expect($payload['staffelgrens_cm'])->toBe(400)
        ->and($tarieven)->toHaveCount(2)
        ->and($tarieven[0]['max_lengte_cm'])->toBe(400)
        ->and($tarieven[0]['tarief'])->toBe(10.89)
        ->and($tarieven[1]['max_lengte_cm'])->toBeNull()
        // 5,25 x 2 x 1,21 = 12,71 (afgerond)
        ->and($tarieven[1]['tarief'])->toBe(12.71);
});

it('levert vaste toeslagen als los bedrag, niet verwerkt in het metertarief', function () {
    $payload = afwerkingService()->payloadVoor(eurogrosMaatwerkParent());
    $festonneren = optieUitPayload($payload, 'festonneren');
    $toeslag = $festonneren['toeslagen'][0];

    expect($toeslag['type'])->toBe('vast')
        ->and($toeslag['voorwaarde'])->toBe('organische_vorm')
        // 15,00 x 2 x 1,21 = 36,30
        ->and($toeslag['bedrag'])->toBe(36.30)
        // het metertarief blijft onaangeroerd door de toeslag
        ->and($festonneren['keuzes'][0]['tarieven'][0]['tarief'])->toBe(10.89);
});

it('legt de optelvolgorde vast die de shop moet reproduceren', function () {
    $payload = afwerkingService()->payloadVoor(eurogrosMaatwerkParent());
    $festonneren = optieUitPayload($payload, 'festonneren');

    $tarief = $festonneren['keuzes'][0]['tarieven'][0]['tarief'];
    $vast = $festonneren['toeslagen'][0]['bedrag'];

    // Organisch kleed van 200 x 300 cm: omtrek van de omschrijvende rechthoek
    // is 2 x (2,00 + 3,00) = 10 m. Toeslag = omtrek x tarief + vast bedrag.
    $omtrekMeter = 2 * (2.00 + 3.00);

    expect(round($omtrekMeter * $tarief + $vast, 2))->toBe(145.20);
});

it('rekent anti-slip per vierkante meter en markeert het als combineerbaar', function () {
    $antiSlip = optieUitPayload(afwerkingService()->payloadVoor(eurogrosMaatwerkParent()), 'anti_slip');

    expect($antiSlip['eenheid'])->toBe('m2')
        ->and($antiSlip['combineerbaar'])->toBeTrue()
        // 10,00 x 2 x 1,21 = 24,20
        ->and($antiSlip['keuzes'][0]['tarieven'][0]['tarief'])->toBe(24.20);
});

it('rekent randafwerkingen per strekkende meter omtrek', function () {
    $payload = afwerkingService()->payloadVoor(eurogrosMaatwerkParent());

    foreach (['festonneren', 'banderen', 'banderen_blind', 'volume', 'biesje'] as $code) {
        expect(optieUitPayload($payload, $code)['eenheid'])->toBe('omtrek_m');
    }
});

it('markeert volume als inclusief onderkleed en kent de ronde toeslag', function () {
    $volume = optieUitPayload(afwerkingService()->payloadVoor(eurogrosMaatwerkParent()), 'volume');

    expect($volume['inclusief_onderkleed'])->toBeTrue()
        ->and(collect($volume['toeslagen'])->firstWhere('voorwaarde', 'ronde_vorm')['bedrag'])->toBe(60.50)
        ->and(collect($volume['toeslagen'])->firstWhere('voorwaarde', 'organische_vorm')['bedrag'])->toBe(121.00);
});

it('geeft null als de feature is uitgeschakeld', function () {
    stelAfwerkingConfigIn('general.afwerkingen.settings.enabled', '0');

    expect(afwerkingService()->payloadVoor(eurogrosMaatwerkParent()))->toBeNull()
        ->and(afwerkingService()->isBeschikbaar(eurogrosMaatwerkParent()))->toBeFalse();
});

it('biedt afwerkingen aan bij de toegestane merken', function (string $merk) {
    expect(afwerkingService()->isBeschikbaar(afwerkingParent(['merk' => $merk])))->toBeTrue();
})->with(['Eurogros', 'Karpi', 'Desso', 'Mart Visser', 'Mart Visser|Karpi']);

it('biedt geen afwerkingen aan bij andere merken', function (string $merk) {
    expect(afwerkingService()->isBeschikbaar(afwerkingParent(['merk' => $merk])))->toBeFalse();
})->with(['De Munk', 'Louis De Poortere', '']);

it('valt terug op het merk van een variant als de parent er geen heeft', function () {
    $parent = afwerkingParent([], [['merk' => 'Karpi']]);

    expect(afwerkingService()->isBeschikbaar($parent))->toBeTrue();
});

it('slaat parents zonder maatwerk-variant over', function () {
    $parent = afwerkingParent(['merk' => 'Eurogros'], [['maat' => '200 cm x 300 cm']]);

    expect(afwerkingService()->isBeschikbaar($parent))->toBeFalse();
});

it('laat de handmatige override "nee" winnen van een toegestaan merk', function () {
    $parent = eurogrosMaatwerkParent(['afwerking_beschikbaar' => 'nee']);

    expect(afwerkingService()->isBeschikbaar($parent))->toBeFalse()
        ->and(afwerkingService()->payloadVoor($parent))->toBeNull();
});

it('laat de handmatige override "ja" winnen van een niet-toegestaan merk', function () {
    $parent = afwerkingParent(['merk' => 'De Munk', 'afwerking_beschikbaar' => 'ja']);

    expect(afwerkingService()->isBeschikbaar($parent))->toBeTrue();
});

it('behandelt een lege override als automatisch', function () {
    expect(afwerkingService()->isBeschikbaar(eurogrosMaatwerkParent(['afwerking_beschikbaar' => ''])))->toBeTrue()
        ->and(afwerkingService()->isBeschikbaar(afwerkingParent(['merk' => 'De Munk', 'afwerking_beschikbaar' => ''])))->toBeFalse();
});

it('laat een uitgeschakeld afwerkingstype weg uit de payload', function () {
    stelAfwerkingConfigIn('general.afwerkingen.types.volume_enabled', '0');

    $payload = afwerkingService()->payloadVoor(eurogrosMaatwerkParent());

    expect(optieUitPayload($payload, 'volume'))->toBeNull()
        ->and(optieUitPayload($payload, 'festonneren'))->not->toBeNull();
});

it('beperkt een afwerkingstype tot de ingestelde merken', function () {
    stelAfwerkingConfigIn('general.afwerkingen.types.biesje_merken', 'Desso,Karpi');

    expect(optieUitPayload(afwerkingService()->payloadVoor(eurogrosMaatwerkParent()), 'biesje'))->toBeNull()
        ->and(optieUitPayload(afwerkingService()->payloadVoor(afwerkingParent(['merk' => 'Desso'])), 'biesje'))->not->toBeNull();
});

it('legt geen beperking op bij een lege merkenlijst', function () {
    stelAfwerkingConfigIn('general.afwerkingen.types.biesje_merken', '');

    expect(optieUitPayload(afwerkingService()->payloadVoor(eurogrosMaatwerkParent()), 'biesje'))->not->toBeNull();
});

it('geeft null als alle afwerkingstypes wegvallen', function () {
    foreach (array_keys(config('afwerkingen.opties')) as $code) {
        stelAfwerkingConfigIn("general.afwerkingen.types.{$code}_enabled", '0');
    }

    expect(afwerkingService()->payloadVoor(eurogrosMaatwerkParent()))->toBeNull();
});
