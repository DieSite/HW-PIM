<?php

use App\Models\CompetitorPrice;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Services\CompetitorPricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\WooCommerce\Listeners\SerializedProcessProductsToWooCommerce;

/**
 * Persist a parent + one variant carrying the given common values, using a
 * recognisable SKU prefix so cleanup never touches real data.
 */
function makePricedVariant(array $common, string $sku = 'CPTEST-V1'): Product
{
    $familyId = DB::table('attribute_families')->value('id')
        ?? DB::table('attribute_families')->insertGetId(['code' => 'fam_'.uniqid(), 'status' => 1]);

    $parent = new Product();
    $parent->attribute_family_id = $familyId;
    $parent->sku = 'CPTEST-PARENT';
    $parent->type = 'configurable';
    $parent->status = 1;
    $parent->values = ['common' => []];
    $parent->save();

    $variant = new Product();
    $variant->attribute_family_id = $familyId;
    $variant->parent_id = $parent->id;
    $variant->sku = $sku;
    $variant->type = 'simple';
    $variant->status = 1;
    $variant->values = ['common' => $common];
    $variant->save();

    return $variant;
}

afterEach(function () {
    Product::where('sku', 'like', 'CPTEST-%')->delete();
    CompetitorPrice::where('sku', 'like', 'CPTEST-%')->delete();
    ProductPriceHistory::where('sku', 'like', 'CPTEST-%')->delete();
});

it('lowers prijs to a cheaper competitor, logs history and syncs', function () {
    Queue::fake();

    $variant = makePricedVariant([
        'prijs'              => ['EUR' => '1000'],
        'adviesverkoopprijs' => ['EUR' => '1000'],
    ]);

    CompetitorPrice::create([
        'sku' => $variant->sku, 'shop' => 'shopa.nl', 'price' => 850,
        'url' => 'https://shopa.nl/rug',
    ]);

    app(CompetitorPricingService::class)->recomputeForSkus([$variant->sku]);

    $variant->refresh();
    expect($variant->values['common']['prijs']['EUR'])->toBe('850');

    $history = ProductPriceHistory::where('sku', $variant->sku)->first();
    expect($history)->not->toBeNull()
        ->and((float) $history->old_price)->toBe(1000.0)
        ->and((float) $history->new_price)->toBe(850.0)
        ->and($history->competitor_shop)->toBe('shopa.nl')
        ->and($history->competitor_url)->toBe('https://shopa.nl/rug')
        ->and($history->reason)->toContain('shopa.nl');

    Queue::assertPushed(SerializedProcessProductsToWooCommerce::class);
});

it('clamps at the floor when a competitor is far below advies', function () {
    Queue::fake();

    $variant = makePricedVariant([
        'prijs'              => ['EUR' => '1000'],
        'adviesverkoopprijs' => ['EUR' => '1000'],
    ]);

    CompetitorPrice::create(['sku' => $variant->sku, 'shop' => 'cheap.nl', 'price' => 500]);

    app(CompetitorPricingService::class)->recomputeForSkus([$variant->sku]);

    $variant->refresh();
    expect($variant->values['common']['prijs']['EUR'])->toBe('750');

    $history = ProductPriceHistory::where('sku', $variant->sku)->first();
    expect($history->reason)->toContain('begrensd');
});

it('does nothing when the price is unchanged', function () {
    Queue::fake();

    $variant = makePricedVariant([
        'prijs'              => ['EUR' => '1000'],
        'adviesverkoopprijs' => ['EUR' => '1000'],
    ]);
    // No competitor -> target is advies -> equals current prijs.

    app(CompetitorPricingService::class)->recomputeForSkus([$variant->sku]);

    expect(ProductPriceHistory::where('sku', $variant->sku)->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('imports and parses competitor prices from a scraper SQLite database', function () {
    $variant = makePricedVariant([
        'prijs'              => ['EUR' => '1000'],
        'adviesverkoopprijs' => ['EUR' => '1000'],
    ]);

    $dbPath = tempnam(sys_get_temp_dir(), 'compdb').'.sqlite';
    $pdo = new \PDO('sqlite:'.$dbPath);
    $pdo->exec('CREATE TABLE prices (sku TEXT, shop TEXT, price_str TEXT, url TEXT, scraped_at TEXT)');
    $insert = $pdo->prepare('INSERT INTO prices VALUES (?, ?, ?, ?, ?)');
    $insert->execute([$variant->sku, 'shopa.nl', '€ 1.234,50', 'https://shopa.nl/x', '2026-06-17 13:07:30']);
    $insert->execute([$variant->sku, 'shopb.nl', 'n.v.t.', null, '2026-06-17 13:07:30']);
    $pdo = null;

    $this->artisan('pricing:import-competitor-prices', ['--db' => $dbPath, '--no-recompute' => true])
        ->assertSuccessful();

    $prices = CompetitorPrice::where('sku', $variant->sku)->get();
    expect($prices)->toHaveCount(1)
        ->and((float) $prices->first()->price)->toBe(1234.50)
        ->and($prices->first()->shop)->toBe('shopa.nl');

    @unlink($dbPath);
});

it('skips the pipeline when competitor analysis is toggled off', function () {
    DB::table('core_config')->updateOrInsert(
        ['code' => 'general.pricing.settings.enabled'],
        ['value' => '0', 'created_at' => now(), 'updated_at' => now()],
    );

    $this->artisan('pricing:run-competitor-analysis')
        ->expectsOutputToContain('Concurrentie-analyse staat uit')
        ->assertSuccessful();

    DB::table('core_config')->where('code', 'general.pricing.settings.enabled')->delete();
});

it('exports the catalog from the database to a scraper CSV', function () {
    $variant = makePricedVariant([
        'productnaam' => 'Diamante 01',
        'maat'        => '170 cm x 240 cm',
        'prijs'       => ['EUR' => 1299],
    ], 'CPTEST-EXPORT');

    // Brand lives on the parent, not the variant.
    $variant->parent->values = ['common' => ['merk' => 'De Munk', 'productnaam' => 'Diamante 01']];
    $variant->parent->save();

    $path = tempnam(sys_get_temp_dir(), 'cat_');
    $rows = app(App\Services\CompetitorCatalogExporter::class)->export($path);
    $csv = file_get_contents($path);
    @unlink($path);

    expect($rows)->toBeGreaterThanOrEqual(1)
        ->and($csv)->toContain('CPTEST-EXPORT,De Munk,Diamante 01,170 cm x 240 cm,1299');
});

it('strips commas so the scraper cannot mis-split a catalog row', function () {
    makePricedVariant([
        'productnaam' => 'Rug, Special',
        'maat'        => 'Maatwerk',
        'prijs'       => ['EUR' => 999],
    ], 'CPTEST-COMMA');

    $path = tempnam(sys_get_temp_dir(), 'cat_');
    app(App\Services\CompetitorCatalogExporter::class)->export($path);
    $csv = file_get_contents($path);
    @unlink($path);

    $line = collect(explode("\n", $csv))->first(fn ($l) => str_starts_with($l, 'CPTEST-COMMA'));

    expect($line)->not->toBeNull()
        ->and(substr_count($line, ','))->toBe(4)
        ->and($line)->toContain('Rug  Special');
});
