<?php

use App\Services\AfwerkingOptieService;
use Illuminate\Support\Facades\DB;

/**
 * An item in the shape Exporter::formatData() works with: the product's
 * toArray() output, with the variants nested under `variants`.
 *
 * @param  array<string, mixed>  $common
 * @param  array<int, array<string, mixed>>  $variants
 * @return array<string, mixed>
 */
function exporterItem(array $common, array $variants = [['maat' => 'Maatwerk']]): array
{
    return [
        'code'     => 'ERG0411',
        'values'   => ['common' => $common],
        'variants' => array_map(
            fn (array $variantCommon): array => ['values' => ['common' => $variantCommon]],
            $variants
        ),
    ];
}

function stelExportConfigIn(string $code, string $value): void
{
    DB::table('core_config')->updateOrInsert(
        ['code' => $code, 'channel_code' => null, 'locale_code' => null],
        ['value' => $value, 'created_at' => now(), 'updated_at' => now()]
    );
}

beforeEach(function () {
    stelExportConfigIn('general.afwerkingen.settings.marge_factor', '2');
});

it('bouwt de payload uit de array die de exporter al in handen heeft', function () {
    $payload = app(AfwerkingOptieService::class)
        ->payloadVoorItem(exporterItem(['merk' => 'Eurogros']));

    expect($payload)->not->toBeNull()
        ->and($payload['versie'])->toBe(AfwerkingOptieService::PAYLOAD_VERSIE)
        ->and($payload['valuta'])->toBe('EUR')
        ->and(collect($payload['opties'])->pluck('code')->all())
        ->toBe(['festonneren', 'banderen', 'banderen_blind', 'volume', 'biesje', 'anti_slip']);
});

it('levert een payload die als geldige JSON de meta in kan', function () {
    $payload = app(AfwerkingOptieService::class)
        ->payloadVoorItem(exporterItem(['merk' => 'Desso']));

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    /**
     * Losse waardevergelijking: een rond getal als 121.0 komt als int 121 uit
     * json_decode terug. Dat is voor de shop hetzelfde getal, dus alleen de
     * waarden hoeven te kloppen, niet de PHP-types.
     */
    expect($json)->toBeString()
        ->and(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and(json_decode($json, true))->toEqual($payload);
});

it('geeft geen payload voor een product van een ander merk', function () {
    expect(app(AfwerkingOptieService::class)->payloadVoorItem(exporterItem(['merk' => 'De Munk'])))->toBeNull();
});

it('geeft geen payload voor een parent zonder maatwerk-variant', function () {
    $item = exporterItem(['merk' => 'Eurogros'], [['maat' => '200 cm x 300 cm']]);

    expect(app(AfwerkingOptieService::class)->payloadVoorItem($item))->toBeNull();
});

it('leest het merk van een variant als de parent er geen heeft', function () {
    $item = exporterItem([], [['maat' => 'Maatwerk', 'merk' => 'Karpi']]);

    expect(app(AfwerkingOptieService::class)->payloadVoorItem($item))->not->toBeNull();
});

it('verdraagt een dubbel-gecodeerde values-kolom', function () {
    $item = [
        'code'     => 'ERG0411',
        'values'   => json_encode(['common' => ['merk' => 'Eurogros']]),
        'variants' => [['values' => json_encode(['common' => ['maat' => 'Maatwerk']])]],
    ];

    expect(app(AfwerkingOptieService::class)->payloadVoorItem($item))->not->toBeNull();
});

it('exporteert het festoneren_banderen-attribuut niet langer', function () {
    $exporter = file_get_contents(
        base_path('packages/Webkul/WooCommerce/src/Helpers/Exporters/Product/Exporter.php')
    );

    /**
     * Het oude blok stuurde de opties van festoneren_banderen mee onder de
     * externalId van maatgroep, waardoor er twee attributen met hetzelfde id
     * in de payload zaten en WooCommerce de maatgroep-opties kon wissen.
     */
    expect($exporter)->not->toContain('festoneren_banderen');
});
