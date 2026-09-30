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

it('leaves variants we never find at a competitor out, even without a competitor price', function (array $common) {
    makeCoverageVariant($common, 'COVTEST-NEVER');

    expect(uncoveredBySku())->not->toHaveKey('COVTEST-NEVER');
})->with([
    'met onderkleed'       => [['onderkleed' => 'Met onderkleed']],
    'maatwerk'             => [['maat' => 'Maatwerk']],
    'maatwerk zonder merk' => [['maat' => 'Maatwerk', 'productnaam' => '']],
]);

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
    $variant = makeCoverageVariant(['maat' => 'Poef'], 'COVTEST-DOUBLE');

    DB::table('products')->where('id', $variant->id)->update([
        'values' => json_encode(json_encode($variant->values)),
    ]);

    expect(uncoveredBySku()['COVTEST-DOUBLE']['reden'])
        ->toBe(CompetitorCoverageAnalyzer::REASON_SIZE);
});

it('never reports the parent itself', function () {
    makeCoverageVariant([], 'COVTEST-PARENTCHECK');

    expect(uncoveredBySku())->not->toHaveKey('COVTEST-PARENTCHECK-PARENT');
});

it('writes an Excel workbook with a row per rug and a summary sheet', function () {
    makeCoverageVariant(['maat' => 'Poef'], 'COVTEST-XLSX-1');
    makeCoverageVariant([], 'COVTEST-XLSX-2');

    $path = storage_path('app/'.uniqid('coverage_').'.xlsx');

    $this->artisan('pricing:export-uncovered-rugs', ['--output' => $path])
        ->assertSuccessful();

    expect(file_exists($path))->toBeTrue();

    $reader = IOFactory::createReader('Xlsx');
    $reader->setReadDataOnly(true);
    $workbook = $reader->load($path);

    expect($workbook->getSheetNames())->toBe(['Per fabrikant', 'Karpi', 'Niet meegenomen', 'Samenvatting']);

    $rows = $workbook->getSheetByName('Niet meegenomen')->toArray();

    expect($rows[0])->toBe([
        'SKU', 'Parent SKU', 'Merk', 'Model', 'Maat', 'Onderkleed',
        'Adviesverkoopprijs', 'Huidige prijs', 'Status', 'Reden', 'Toelichting',
    ])
        ->and(array_column(array_slice($rows, 1), 0))
        ->toEqualCanonicalizing(['COVTEST-XLSX-1', 'COVTEST-XLSX-2']);

    // toArray() geeft opgemaakte celwaarden terug, dus getallen komen als tekst binnen.
    $summary = collect($workbook->getSheetByName('Samenvatting')->toArray())
        ->mapWithKeys(fn (array $row): array => [$row[0] => (int) $row[1]]);

    expect($summary[CompetitorCoverageAnalyzer::REASON_SIZE])->toBe(1)
        ->and($summary[CompetitorCoverageAnalyzer::REASON_NO_MATCH])->toBe(1)
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
    makeCoverageVariant(['maat' => 'Poef'], 'COVTEST-FILTER-1');
    makeCoverageVariant([], 'COVTEST-FILTER-2');

    $path = storage_path('app/'.uniqid('coverage_').'.xlsx');

    $this->artisan('pricing:export-uncovered-rugs', [
        '--output' => $path,
        '--reason' => [CompetitorCoverageAnalyzer::REASON_SIZE],
    ])->assertSuccessful();

    $reader = IOFactory::createReader('Xlsx');
    $reader->setReadDataOnly(true);
    $rows = $reader->load($path)->getSheetByName('Niet meegenomen')->toArray();

    expect(array_column(array_slice($rows, 1), 0))->toBe(['COVTEST-FILTER-1']);

    @unlink($path);
});

it('splits the uncovered rugs per manufacturer', function () {
    makeCoverageVariant(['maat' => 'Poef'], 'COVTEST-BRAND-1', ['merk' => 'Karpi']);
    makeCoverageVariant([], 'COVTEST-BRAND-2', ['merk' => 'Karpi']);
    makeCoverageVariant([], 'COVTEST-BRAND-3', ['merk' => 'De Munk']);
    makeCoverageVariant([], 'COVTEST-BRAND-4', []);
    makeCoverageVariant([], 'COVTEST-BRAND-5', ['merk' => 'Karpi/Mart: Visser [x]']);

    $path = storage_path('app/'.uniqid('coverage_').'.xlsx');

    $this->artisan('pricing:export-uncovered-rugs', ['--output' => $path])
        ->expectsOutputToContain('Fabrikant')
        ->assertSuccessful();

    $reader = IOFactory::createReader('Xlsx');
    $reader->setReadDataOnly(true);
    $workbook = $reader->load($path);

    expect($workbook->getSheetNames())->toBe([
        'Per fabrikant', 'Karpi', 'De Munk', 'Karpi Mart  Visser  x', 'Zonder merk', 'Niet meegenomen', 'Samenvatting',
    ]);

    $skusOn = fn (string $sheet): array => array_column(array_slice($workbook->getSheetByName($sheet)->toArray(), 1), 0);

    expect($skusOn('Karpi'))->toEqualCanonicalizing(['COVTEST-BRAND-1', 'COVTEST-BRAND-2'])
        ->and($skusOn('De Munk'))->toBe(['COVTEST-BRAND-3'])
        ->and($skusOn('Zonder merk'))->toBe(['COVTEST-BRAND-4']);

    $overview = $workbook->getSheetByName('Per fabrikant')->toArray();

    expect($overview[0])->toBe([
        'Fabrikant', 'Totaal',
        CompetitorCoverageAnalyzer::REASON_BRAND,
        CompetitorCoverageAnalyzer::REASON_SIZE,
        CompetitorCoverageAnalyzer::REASON_NO_MATCH,
    ]);

    $byBrand = collect(array_slice($overview, 1))
        ->mapWithKeys(fn (array $row): array => [$row[0] => array_map('intval', array_slice($row, 1))]);

    expect($byBrand['Karpi'])->toBe([2, 0, 1, 1])
        ->and($byBrand['De Munk'])->toBe([1, 0, 0, 1])
        ->and($byBrand['Zonder merk'])->toBe([1, 1, 0, 0])
        ->and($byBrand['Totaal'])->toBe([5, 1, 1, 3]);

    @unlink($path);
});
