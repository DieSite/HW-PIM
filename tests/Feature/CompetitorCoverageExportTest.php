<?php

use App\Models\CompetitorPrice;
use App\Models\Product;
use App\Services\CompetitorCoverageAnalyzer;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Persist a parent + one variant, with the brand on the parent exactly as the
 * catalog export reads it.
 *
 * @param  array<string, mixed>  $common
 * @param  array<string, mixed>  $parentCommon
 */
function makeCoverageVariant(array $common, string $sku, array $parentCommon = ['merk' => 'Karpi']): Product
{
    $familyId = DB::table('attribute_families')->value('id')
        ?? DB::table('attribute_families')->insertGetId(['code' => 'fam_'.uniqid(), 'status' => 1]);

    $parent = new Product();
    $parent->attribute_family_id = $familyId;
    $parent->sku = $sku.'-PARENT';
    $parent->type = 'configurable';
    $parent->status = 1;
    $parent->values = ['common' => $parentCommon];
    $parent->save();

    $variant = new Product();
    $variant->attribute_family_id = $familyId;
    $variant->parent_id = $parent->id;
    $variant->sku = $sku;
    $variant->type = 'simple';
    $variant->status = 1;
    $variant->values = ['common' => $common + [
        'productnaam'        => 'Cisco 12',
        'maat'               => '200 cm x 290 cm',
        'onderkleed'         => 'Zonder onderkleed',
        'adviesverkoopprijs' => ['EUR' => '1000'],
        'prijs'              => ['EUR' => '900'],
    ]];
    $variant->save();

    return $variant;
}

/**
 * The export rows keyed by SKU.
 *
 * @return array<string, array<string, mixed>>
 */
function uncoveredBySku(): array
{
    $rows = [];

    foreach (app(CompetitorCoverageAnalyzer::class)->uncovered() as $row) {
        $rows[$row['sku']] = $row;
    }

    return $rows;
}

it('leaves a rug with a competitor price out of the export', function () {
    $variant = makeCoverageVariant([], 'COVTEST-OK');

    CompetitorPrice::create([
        'sku' => $variant->sku, 'shop' => 'shopa.nl', 'price' => 850,
    ]);

    expect(uncoveredBySku())->not->toHaveKey($variant->sku);
});

it('reports a rug nobody could be matched to as "geen concurrent gevonden"', function () {
    makeCoverageVariant([], 'COVTEST-NOMATCH');

    expect(uncoveredBySku()['COVTEST-NOMATCH']['reden'])
        ->toBe(CompetitorCoverageAnalyzer::REASON_NO_MATCH);
});

it('ignores a competitor row without a real price', function () {
    $variant = makeCoverageVariant([], 'COVTEST-ZERO');

    CompetitorPrice::create([
        'sku' => $variant->sku, 'shop' => 'shopa.nl', 'price' => 0,
    ]);

    expect(uncoveredBySku()[$variant->sku]['reden'])
        ->toBe(CompetitorCoverageAnalyzer::REASON_NO_MATCH);
});

it('names the reason a rug falls outside the analysis', function (array $common, array $parentCommon, string $expected) {
    makeCoverageVariant($common, 'COVTEST-REASON', $parentCommon);

    expect(uncoveredBySku()['COVTEST-REASON']['reden'])->toBe($expected);
})->with([
    'met onderkleed' => [
        ['onderkleed' => 'Met onderkleed'],
        ['merk' => 'Karpi'],
        CompetitorCoverageAnalyzer::REASON_UNDERLAY,
    ],
    'maatwerk' => [
        ['maat' => 'Maatwerk'],
        ['merk' => 'Karpi'],
        CompetitorCoverageAnalyzer::REASON_MAATWERK,
    ],
    'geen merk' => [
        [],
        [],
        CompetitorCoverageAnalyzer::REASON_BRAND,
    ],
    'geen modelnaam' => [
        ['productnaam' => ''],
        ['merk' => 'Karpi'],
        CompetitorCoverageAnalyzer::REASON_MODEL,
    ],
    'onleesbare maat' => [
        ['maat' => 'Poef'],
        ['merk' => 'Karpi'],
        CompetitorCoverageAnalyzer::REASON_SIZE,
    ],
    'maat buiten het bereik van een kleed' => [
        ['maat' => '20 cm x 30 cm'],
        ['merk' => 'Karpi'],
        CompetitorCoverageAnalyzer::REASON_SIZE,
    ],
    'geen adviesverkoopprijs' => [
        ['adviesverkoopprijs' => ['EUR' => '']],
        ['merk' => 'Karpi'],
        CompetitorCoverageAnalyzer::REASON_ADVIES,
    ],
]);

it('reads a rug whose values column is double encoded', function () {
    $variant = makeCoverageVariant(['maat' => 'Maatwerk'], 'COVTEST-DOUBLE');

    DB::table('products')->where('id', $variant->id)->update([
        'values' => json_encode(json_encode($variant->values)),
    ]);

    expect(uncoveredBySku()['COVTEST-DOUBLE']['reden'])
        ->toBe(CompetitorCoverageAnalyzer::REASON_MAATWERK);
});

it('never reports the parent itself', function () {
    makeCoverageVariant([], 'COVTEST-PARENTCHECK');

    expect(uncoveredBySku())->not->toHaveKey('COVTEST-PARENTCHECK-PARENT');
});

it('writes an Excel workbook with a row per rug and a summary sheet', function () {
    makeCoverageVariant(['maat' => 'Maatwerk'], 'COVTEST-XLSX-1');
    makeCoverageVariant(['onderkleed' => 'Met onderkleed'], 'COVTEST-XLSX-2');

    $path = storage_path('app/'.uniqid('coverage_').'.xlsx');

    $this->artisan('pricing:export-uncovered-rugs', ['--output' => $path])
        ->assertSuccessful();

    expect(file_exists($path))->toBeTrue();

    $reader = IOFactory::createReader('Xlsx');
    $reader->setReadDataOnly(true);
    $workbook = $reader->load($path);

    expect($workbook->getSheetNames())->toBe(['Niet meegenomen', 'Samenvatting']);

    $rows = $workbook->getSheet(0)->toArray();

    expect($rows[0])->toBe([
        'SKU', 'Parent SKU', 'Merk', 'Model', 'Maat', 'Onderkleed',
        'Adviesverkoopprijs', 'Huidige prijs', 'Status', 'Reden', 'Toelichting',
    ])
        ->and(array_column(array_slice($rows, 1), 0))
        ->toEqualCanonicalizing(['COVTEST-XLSX-1', 'COVTEST-XLSX-2']);

    // toArray() geeft opgemaakte celwaarden terug, dus getallen komen als tekst binnen.
    $summary = collect($workbook->getSheet(1)->toArray())
        ->mapWithKeys(fn (array $row): array => [$row[0] => (int) $row[1]]);

    expect($summary[CompetitorCoverageAnalyzer::REASON_MAATWERK])->toBe(1)
        ->and($summary[CompetitorCoverageAnalyzer::REASON_UNDERLAY])->toBe(1)
        ->and($summary['Totaal'])->toBe(2);

    @unlink($path);
});

it('refuses an unknown reason filter instead of writing an empty file', function () {
    $path = storage_path('app/'.uniqid('coverage_').'.xlsx');

    $this->artisan('pricing:export-uncovered-rugs', ['--output' => $path, '--reason' => ['Geen idee']])
        ->expectsOutputToContain('Onbekende reden: Geen idee')
        ->assertFailed();

    expect(file_exists($path))->toBeFalse();
});

it('exports only the requested reason', function () {
    makeCoverageVariant(['maat' => 'Maatwerk'], 'COVTEST-FILTER-1');
    makeCoverageVariant(['onderkleed' => 'Met onderkleed'], 'COVTEST-FILTER-2');

    $path = storage_path('app/'.uniqid('coverage_').'.xlsx');

    $this->artisan('pricing:export-uncovered-rugs', [
        '--output' => $path,
        '--reason' => [CompetitorCoverageAnalyzer::REASON_MAATWERK],
    ])->assertSuccessful();

    $reader = IOFactory::createReader('Xlsx');
    $reader->setReadDataOnly(true);
    $rows = $reader->load($path)->getSheet(0)->toArray();

    expect(array_column(array_slice($rows, 1), 0))->toBe(['COVTEST-FILTER-1']);

    @unlink($path);
});
