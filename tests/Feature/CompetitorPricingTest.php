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
    $parent->sku = $sku.'-PARENT';
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

it('prunes competitor prices that are no longer in the scraper database', function () {
    $variant = makePricedVariant([
        'prijs'              => ['EUR' => '1000'],
        'adviesverkoopprijs' => ['EUR' => '1000'],
    ]);

    CompetitorPrice::create([
        'sku' => $variant->sku, 'shop' => 'stale.nl', 'price' => 700,
        'url' => 'https://stale.nl/rug-rechthoek',
    ]);

    $dbPath = tempnam(sys_get_temp_dir(), 'compdb').'.sqlite';
    $pdo = new \PDO('sqlite:'.$dbPath);
    $pdo->exec('CREATE TABLE prices (sku TEXT, shop TEXT, price_str TEXT, url TEXT, scraped_at TEXT)');
    $pdo->prepare('INSERT INTO prices VALUES (?, ?, ?, ?, ?)')
        ->execute([$variant->sku, 'shopa.nl', '€ 900,00', 'https://shopa.nl/x', '2026-06-17 13:07:30']);
    $pdo = null;

    // Without --prune the stale row survives.
    $this->artisan('pricing:import-competitor-prices', ['--db' => $dbPath, '--no-recompute' => true])
        ->assertSuccessful();
    expect(CompetitorPrice::where('sku', $variant->sku)->pluck('shop')->sort()->values()->all())
        ->toBe(['shopa.nl', 'stale.nl']);

    // With --prune it is removed; the freshly scraped one stays.
    $this->artisan('pricing:import-competitor-prices', ['--db' => $dbPath, '--no-recompute' => true, '--prune' => true])
        ->expectsOutputToContain('Pruned 1 competitor prices')
        ->assertSuccessful();
    expect(CompetitorPrice::where('sku', $variant->sku)->pluck('shop')->all())->toBe(['shopa.nl']);

    @unlink($dbPath);
});

it('prunes via the full pipeline wrapper', function () {
    $variant = makePricedVariant([
        'prijs'              => ['EUR' => '1000'],
        'adviesverkoopprijs' => ['EUR' => '1000'],
    ]);

    CompetitorPrice::create(['sku' => $variant->sku, 'shop' => 'stale.nl', 'price' => 700]);

    $dbPath = tempnam(sys_get_temp_dir(), 'compdb').'.sqlite';
    $pdo = new \PDO('sqlite:'.$dbPath);
    $pdo->exec('CREATE TABLE prices (sku TEXT, shop TEXT, price_str TEXT, url TEXT, scraped_at TEXT)');
    $pdo->prepare('INSERT INTO prices VALUES (?, ?, ?, ?, ?)')
        ->execute([$variant->sku, 'shopa.nl', '€ 900,00', 'https://shopa.nl/x', '2026-06-17 13:07:30']);
    $pdo = null;

    config(['competitor_pricing.db_path' => $dbPath]);

    $this->artisan('pricing:run-competitor-analysis', ['--skip-scrape' => true, '--no-recompute' => true])
        ->assertSuccessful();

    expect(CompetitorPrice::where('sku', $variant->sku)->pluck('shop')->all())->toBe(['shopa.nl']);

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

it('reverts system-applied discounts but leaves manual discounts alone', function () {
    Queue::fake();

    $system = makePricedVariant([
        'prijs'              => ['EUR' => '750'],
        'adviesverkoopprijs' => ['EUR' => '1000'],
    ], 'CPTEST-REVERT-SYS');

    ProductPriceHistory::create([
        'product_id' => $system->id,
        'sku'        => $system->sku,
        'old_price'  => 1000,
        'new_price'  => 750,
        'reason'     => 'Concurrent test.nl biedt € 750,00 — laagste concurrent.',
        'changed_at' => now(),
    ]);

    $manual = makePricedVariant([
        'prijs'              => ['EUR' => '800'],
        'adviesverkoopprijs' => ['EUR' => '1000'],
    ], 'CPTEST-REVERT-MAN');

    $this->artisan('pricing:revert-discounted-prices', ['--force' => true])
        ->assertSuccessful();

    $system->refresh();
    $manual->refresh();
    expect($system->values['common']['prijs']['EUR'])->toBe('1000')
        ->and($manual->values['common']['prijs']['EUR'])->toBe('800');

    $latest = ProductPriceHistory::where('sku', $system->sku)->orderByDesc('id')->first();
    expect((float) $latest->old_price)->toBe(750.0)
        ->and((float) $latest->new_price)->toBe(1000.0)
        ->and($latest->reason)->toContain('revert-discounted-prices');

    Queue::assertPushed(SerializedProcessProductsToWooCommerce::class);
});

it('reverts manual discounts only with --all, and --dry-run changes nothing', function () {
    Queue::fake();

    $manual = makePricedVariant([
        'prijs'              => ['EUR' => '800'],
        'adviesverkoopprijs' => ['EUR' => '1000'],
    ], 'CPTEST-REVERT-DRY');

    $this->artisan('pricing:revert-discounted-prices', ['--all' => true, '--dry-run' => true])
        ->assertSuccessful();
    expect($manual->refresh()->values['common']['prijs']['EUR'])->toBe('800');

    $this->artisan('pricing:revert-discounted-prices', ['--all' => true, '--force' => true, '--no-sync' => true])
        ->assertSuccessful();
    expect($manual->refresh()->values['common']['prijs']['EUR'])->toBe('1000');

    Queue::assertNothingPushed();
});

it('disables the nightly analysis with --disable-analysis', function () {
    $this->artisan('pricing:revert-discounted-prices', ['--force' => true, '--disable-analysis' => true])
        ->assertSuccessful();

    expect(DB::table('core_config')->where('code', 'general.pricing.settings.enabled')->value('value'))->toBe('0');

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

    // 6 velden = 5 komma's (SKU, Merk, Model, Maat, Prijs, Kleuren)
    expect($line)->not->toBeNull()
        ->and(substr_count($line, ','))->toBe(5)
        ->and($line)->toContain('Rug  Special');
});

/**
 * A parent with both onderkleed variants of one size, so the "Met onderkleed"
 * price can be derived from its "Zonder onderkleed" sibling.
 *
 * @return array{0: Product, 1: Product} [zonder, met]
 */
function makeOnderkleedPair(string $maat, float $zonderAdvies, float $metPrijs, ?float $metAdvies = null): array
{
    // In de echte data loopt de advies van de bundel exact de toeslag uit op de
    // kale variant; de derde parameter is alleen de HUIDIGE prijs.
    $metAdvies ??= $metPrijs;

    $zonder = makePricedVariant([
        'productnaam'        => 'Diamante 01',
        'maat'               => $maat,
        'onderkleed'         => 'Zonder onderkleed',
        'prijs'              => ['EUR' => (string) (int) $zonderAdvies],
        'adviesverkoopprijs' => ['EUR' => (string) (int) $zonderAdvies],
    ], 'CPTEST-ZO');

    $met = new Product();
    $met->attribute_family_id = $zonder->attribute_family_id;
    $met->parent_id = $zonder->parent_id;
    $met->sku = 'CPTEST-ZO.O';
    $met->type = 'simple';
    $met->status = 1;
    $met->values = ['common' => [
        'productnaam'        => 'Diamante 01',
        'maat'               => $maat,
        'onderkleed'         => 'Met onderkleed',
        'prijs'              => ['EUR' => (string) (int) $metPrijs],
        'adviesverkoopprijs' => ['EUR' => (string) (int) $metAdvies],
    ]];
    $met->save();

    return [$zonder, $met];
}

it('leaves met-onderkleed variants out of the scraper catalog', function () {
    // No competitor sells the rug bundled with an underlay, so pricing that
    // variant against a rug-only page silently erased the surcharge.
    makeOnderkleedPair('170 cm x 240 cm', 1299, 1329);

    $path = tempnam(sys_get_temp_dir(), 'cat_');
    app(App\Services\CompetitorCatalogExporter::class)->export($path);
    $csv = file_get_contents($path);
    @unlink($path);

    expect($csv)->toContain('CPTEST-ZO,')
        ->and($csv)->not->toContain('CPTEST-ZO.O,');
});

it('carries a discount over to the met-onderkleed sibling with the surcharge intact', function () {
    Queue::fake();

    $maat = '170 cm x 240 cm';
    $surcharge = config('rugs.underrugs_cost')[$maat];
    [$zonder, $met] = makeOnderkleedPair($maat, 1299, 1299 + $surcharge);

    CompetitorPrice::create([
        'sku' => $zonder->sku, 'shop' => 'kleed.nl', 'price' => 1100.00,
        'url' => 'https://www.kleed.nl/diamante-01.html', 'scraped_at' => now(),
    ]);

    app(CompetitorPricingService::class)->recomputeForSkus([$zonder->sku]);

    expect((float) $zonder->fresh()->values['common']['prijs']['EUR'])->toBe(1100.0)
        ->and((float) $met->fresh()->values['common']['prijs']['EUR'])->toBe(1100.0 + $surcharge);

    // The derivation is logged, so the price history explains the bundle too.
    $history = ProductPriceHistory::where('sku', $met->sku)->latest('id')->first();
    expect($history)->not->toBeNull()
        ->and((float) $history->new_price)->toBe(1100.0 + $surcharge)
        ->and($history->reason)->toContain('onderkleedtoeslag');
});

it('carries the discount over for a round size however the maat is spelled', function () {
    Queue::fake();

    // De catalogus schrijft dezelfde ronde maat op meerdere manieren; de
    // tarieventabel kent er maar één. Zonder normalisatie bleef de bundel op
    // zijn oude prijs staan.
    $surcharge = config('rugs.underrugs_cost')['Rond 240 cm'];
    [$zonder, $met] = makeOnderkleedPair('240 cm Rond', 1299, 1299 + $surcharge);

    CompetitorPrice::create([
        'sku' => $zonder->sku, 'shop' => 'kleed.nl', 'price' => 1100.00,
        'url' => 'https://www.kleed.nl/diamante-01.html', 'scraped_at' => now(),
    ]);

    app(CompetitorPricingService::class)->recomputeForSkus([$zonder->sku]);

    expect((float) $met->fresh()->values['common']['prijs']['EUR'])->toBe(1100.0 + $surcharge);
});

it('does not touch the met-onderkleed sibling when the size has no known surcharge', function () {
    Queue::fake();

    [$zonder, $met] = makeOnderkleedPair('123 cm x 456 cm', 1299, 1500);
    $before = $met->values['common']['prijs']['EUR'];

    CompetitorPrice::create([
        'sku' => $zonder->sku, 'shop' => 'kleed.nl', 'price' => 1100.00,
        'url' => 'https://www.kleed.nl/diamante-01.html', 'scraped_at' => now(),
    ]);

    app(CompetitorPricingService::class)->recomputeForSkus([$zonder->sku]);

    expect($met->fresh()->values['common']['prijs']['EUR'])->toBe($before);
});

it('ignores a "Vanaf" price, which is the competitor smallest size not ours', function () {
    $db = tempnam(sys_get_temp_dir(), 'scr_').'.sqlite';
    $pdo = new PDO('sqlite:'.$db);
    $pdo->exec('CREATE TABLE prices (sku TEXT, shop TEXT, price_str TEXT, url TEXT, scraped_at TEXT)');
    $pdo->exec("INSERT INTO prices VALUES
        ('CPTEST-VANAF', 'detafelaar.nl', 'Vanaf € 359,00', 'https://detafelaar.nl/p', '2026-07-29 10:00:00'),
        ('CPTEST-VANAF', 'kleed.nl', '€ 429,00', 'https://www.kleed.nl/p', '2026-07-29 10:00:00')");

    $this->artisan('pricing:import-competitor-prices', ['--db' => $db, '--no-recompute' => true])
        ->assertSuccessful();
    @unlink($db);

    expect(CompetitorPrice::where('sku', 'CPTEST-VANAF')->pluck('shop')->all())->toBe(['kleed.nl']);
});

it('never lets a leftover .O competitor row overwrite the derived bundle price', function () {
    // Until the old .O rows are purged they still sit in competitor_prices. Two
    // writers for one field made the outcome depend on chunkById order; the
    // met-onderkleed variant must simply never be priced from a competitor.
    Queue::fake();

    $maat = '170 cm x 240 cm';
    $surcharge = config('rugs.underrugs_cost')[$maat];
    [$zonder, $met] = makeOnderkleedPair($maat, 1299, 1299 + $surcharge);

    foreach ([$zonder->sku, $met->sku] as $sku) {
        CompetitorPrice::create([
            'sku' => $sku, 'shop' => 'kleed.nl', 'price' => 1100.00,
            'url' => 'https://www.kleed.nl/diamante-01.html', 'scraped_at' => now(),
        ]);
    }

    // Both orders must land on the same answer.
    app(CompetitorPricingService::class)->recomputeForSkus([$zonder->sku, $met->sku]);
    expect((float) $met->fresh()->values['common']['prijs']['EUR'])->toBe(1100.0 + $surcharge);

    app(CompetitorPricingService::class)->recomputeForSkus([$met->sku, $zonder->sku]);
    expect((float) $met->fresh()->values['common']['prijs']['EUR'])->toBe(1100.0 + $surcharge);

    // recompute() itself refuses the bundle outright.
    expect(app(CompetitorPricingService::class)->recompute($met->fresh()))->toBeNull();
});

it('repairs a stale met-onderkleed price even when the base price does not move', function () {
    Queue::fake();

    $maat = '170 cm x 240 cm';
    $surcharge = config('rugs.underrugs_cost')[$maat];
    // Base already correct at 1100; sibling stuck at the rug-only price while
    // its own advies is the proper base-advies + surcharge.
    [$zonder, $met] = makeOnderkleedPair($maat, 1100, 1100, 1299 + $surcharge);
    $zonder->values = array_replace_recursive($zonder->values, [
        'common' => ['adviesverkoopprijs' => ['EUR' => '1299']],
    ]);
    $zonder->saveQuietly();

    CompetitorPrice::create([
        'sku' => $zonder->sku, 'shop' => 'kleed.nl', 'price' => 1100.00,
        'url' => 'https://www.kleed.nl/diamante-01.html', 'scraped_at' => now(),
    ]);

    app(CompetitorPricingService::class)->recomputeForSkus([$zonder->sku]);

    expect((float) $zonder->fresh()->values['common']['prijs']['EUR'])->toBe(1100.0)
        ->and((float) $met->fresh()->values['common']['prijs']['EUR'])->toBe(1100.0 + $surcharge);
});

it('keeps met-onderkleed variants out of the catalog even with double-encoded values', function () {
    // ~3888 legacy products store `values` as a JSON string of the object; a
    // SQL JSON path returns NULL for those, so the filter has to run in PHP.
    [, $met] = makeOnderkleedPair('170 cm x 240 cm', 1299, 1341);

    DB::table('products')->where('id', $met->id)
        ->update(['values' => json_encode(json_encode($met->values))]);

    $path = tempnam(sys_get_temp_dir(), 'cat_');
    app(App\Services\CompetitorCatalogExporter::class)->export($path);
    $csv = file_get_contents($path);
    @unlink($path);

    expect($csv)->toContain('CPTEST-ZO,')
        ->and($csv)->not->toContain('CPTEST-ZO.O,');
});

it('exports the parent colour so the matcher can decide colour variants', function () {
    // Models like "Oasis 11" carry no colour word in their name, so a competitor
    // page that names only a colour is otherwise undecidable.
    $variant = makePricedVariant([
        'productnaam' => 'Oasis 11',
        'maat'        => '200 cm x 290 cm',
        'prijs'       => ['EUR' => 599],
    ], 'CPTEST-KLEUR');

    $parent = $variant->parent;
    $parent->values = ['common' => ['merk' => 'Mart Visser', 'kleuren' => 'Wit']];
    $parent->saveQuietly();

    $path = tempnam(sys_get_temp_dir(), 'cat_');
    app(App\Services\CompetitorCatalogExporter::class)->export($path);
    $csv = file_get_contents($path);
    @unlink($path);

    expect($csv)->toContain('CPTEST-KLEUR,Mart Visser,Oasis 11,200 cm x 290 cm,599,Wit');
});

it('never writes the bare surcharge when the base variant has no usable price', function () {
    // The repair path runs on every base variant, so a base without a price
    // would otherwise derive 0 + surcharge and put €30 in the shop.
    Queue::fake();

    $maat = '170 cm x 240 cm';
    [$zonder, $met] = makeOnderkleedPair($maat, 1299, 1329);

    // Base has neither prijs nor adviesverkoopprijs.
    $zonder->values = ['common' => ['productnaam' => 'Diamante 01', 'maat' => $maat, 'onderkleed' => 'Zonder onderkleed']];
    $zonder->saveQuietly();

    app(CompetitorPricingService::class)->recomputeForSkus([$zonder->sku]);

    expect((float) $met->fresh()->values['common']['prijs']['EUR'])->toBe(1329.0)
        ->and(app(CompetitorPricingService::class)->applyUnderlayPrice($zonder->fresh(), 0.0))->toBeNull();

    // A zero price is just as unusable as a missing one.
    $zonder->values = array_replace_recursive($zonder->values, [
        'common' => ['prijs' => ['EUR' => '0'], 'adviesverkoopprijs' => ['EUR' => '0']],
    ]);
    $zonder->saveQuietly();

    app(CompetitorPricingService::class)->recomputeForSkus([$zonder->sku]);

    expect((float) $met->fresh()->values['common']['prijs']['EUR'])->toBe(1329.0);
});

it('exports a multi-value colour attribute without mangling it', function () {
    // Several real products carry more than one colour ("Zwart, Beige"); a
    // multiselect may reach us as an array, which would export as "Array".
    $variant = makePricedVariant([
        'productnaam' => 'Cendre 65',
        'maat'        => '200 cm x 290 cm',
        'prijs'       => ['EUR' => 599],
    ], 'CPTEST-MULTI');

    $parent = $variant->parent;
    $parent->values = ['common' => ['merk' => 'Mart Visser', 'kleuren' => ['Oranje', 'Geel']]];
    $parent->saveQuietly();

    $path = tempnam(sys_get_temp_dir(), 'cat_');
    app(App\Services\CompetitorCatalogExporter::class)->export($path);
    $csv = file_get_contents($path);
    @unlink($path);

    $line = collect(explode("\n", $csv))->first(fn ($l) => str_starts_with($l, 'CPTEST-MULTI'));

    expect($line)->toContain('Oranje Geel')
        ->and($line)->not->toContain('Array')
        ->and(substr_count($line, ','))->toBe(5);
});

it('still derives the bundle price for a legitimately cheap base variant', function () {
    // The <= 0 guard must not swallow real low prices: one careless edit turns
    // it into "< 100" and silently stops repairing the cheap half of the range.
    Queue::fake();

    $maat = '170 cm x 240 cm';
    $surcharge = config('rugs.underrugs_cost')[$maat];
    [$zonder, $met] = makeOnderkleedPair($maat, 1, 999, 1 + $surcharge);
    $zonder->values = array_replace_recursive($zonder->values, [
        'common' => ['prijs' => ['EUR' => '1'], 'adviesverkoopprijs' => ['EUR' => '1']],
    ]);
    $zonder->saveQuietly();

    expect(app(CompetitorPricingService::class)->applyUnderlayPrice($zonder->fresh(), 1.0))->not->toBeNull()
        ->and((float) $met->fresh()->values['common']['prijs']['EUR'])->toBe(1.0 + $surcharge);
});

it('never sells the bundle above its own advies price', function () {
    Queue::fake();

    $maat = '170 cm x 240 cm';
    $surcharge = config('rugs.underrugs_cost')[$maat];
    // Bundle advies sits just under base + surcharge, so the ceiling binds.
    [$zonder, $met] = makeOnderkleedPair($maat, 1299, 1329, 1310);

    $sibling = app(CompetitorPricingService::class)->applyUnderlayPrice($zonder->fresh(), 1299.0);

    expect($sibling)->not->toBeNull()
        ->and(1299.0 + $surcharge)->toBeGreaterThan(1310.0)
        ->and((float) $met->fresh()->values['common']['prijs']['EUR'])->toBe(1310.0);
});

it('applies no discount floor of its own to the derived bundle price', function () {
    // The floor exists to stop us undercutting a competitor. A derived bundle
    // has no competitor, and the base price is already floored — so borrowing
    // computePrice here turned drifted advies data into €2250 for a €1000 rug.
    Queue::fake();

    $maat = '170 cm x 240 cm';
    $surcharge = config('rugs.underrugs_cost')[$maat];
    [$zonder, $met] = makeOnderkleedPair($maat, 1000, 1030, 3000);

    app(CompetitorPricingService::class)->applyUnderlayPrice($zonder->fresh(), 1000.0);

    expect((float) $met->fresh()->values['common']['prijs']['EUR'])->toBe(1000.0 + $surcharge);
});

it('refuses to write a bundle price below the bare rug', function () {
    // Only reachable when the two advies prices have drifted apart; writing the
    // inversion would be worse than leaving the stale price and warning.
    Queue::fake();

    $maat = '170 cm x 240 cm';
    [$zonder, $met] = makeOnderkleedPair($maat, 1299, 1329, 1000);

    $sibling = app(CompetitorPricingService::class)->applyUnderlayPrice($zonder->fresh(), 1299.0);

    expect($sibling)->toBeNull()
        ->and((float) $met->fresh()->values['common']['prijs']['EUR'])->toBe(1329.0);
});

/**
 * The handmatige extra korting: a per-rug discount applied ON TOP of whatever
 * the competitor logic computed, deliberately breaking through the normal
 * max-discount floor. That is the point of the field — it is how a single rug
 * gets a custom discount without the nightly run flattening it again.
 */
it('applies a manual extra discount on top of the competitor price', function () {
    Queue::fake();

    $variant = makePricedVariant([
        'prijs'              => ['EUR' => '1000'],
        'adviesverkoopprijs' => ['EUR' => '1000'],
        'extra_korting'      => 10,
    ]);

    CompetitorPrice::create([
        'sku' => $variant->sku, 'shop' => 'shopa.nl', 'price' => 850, 'url' => null,
    ]);

    app(CompetitorPricingService::class)->recomputeForSkus([$variant->sku]);

    /** 850 from the competitor, then −10% = 765 — below the 25% floor of 750? No: 765 > 750, but the floor no longer binds it. */
    expect($variant->fresh()->values['common']['prijs']['EUR'])->toBe('765');
});

it('lets the manual discount break through the normal max-discount floor', function () {
    Queue::fake();

    $variant = makePricedVariant([
        'prijs'              => ['EUR' => '1000'],
        'adviesverkoopprijs' => ['EUR' => '1000'],
        'extra_korting'      => 40,
    ]);

    app(CompetitorPricingService::class)->recomputeForSkus([$variant->sku]);

    /**
     * No competitor, so the algorithm lands on the advies price (1000) and the
     * 25% floor would normally stop anything below 750. The manual discount is
     * applied after that bound, so 600 is the whole point.
     */
    expect($variant->fresh()->values['common']['prijs']['EUR'])->toBe('600');
});

/**
 * The compounding was chosen deliberately: a competitor drop and the manual
 * discount stack, so the rug stays the agreed percentage below the market
 * rather than drifting back up.
 */
it('compounds the manual discount with a competitor drop', function () {
    Queue::fake();

    $variant = makePricedVariant([
        'prijs'              => ['EUR' => '1000'],
        'adviesverkoopprijs' => ['EUR' => '1000'],
        'extra_korting'      => 10,
    ]);

    CompetitorPrice::create([
        'sku' => $variant->sku, 'shop' => 'shopa.nl', 'price' => 500, 'url' => null,
    ]);

    app(CompetitorPricingService::class)->recomputeForSkus([$variant->sku]);

    /** Competitor 500 is below the 750 floor, so the algorithm clamps to 750, then −10% = 675. */
    expect($variant->fresh()->values['common']['prijs']['EUR'])->toBe('675');
});

it('inherits the manual discount from the parent so a whole model can be discounted at once', function () {
    Queue::fake();

    $variant = makePricedVariant([
        'prijs'              => ['EUR' => '1000'],
        'adviesverkoopprijs' => ['EUR' => '1000'],
    ]);

    $parent = $variant->parent;
    $parent->values = ['common' => ['extra_korting' => 20]];
    $parent->save();

    app(CompetitorPricingService::class)->recomputeForSkus([$variant->sku]);

    expect($variant->fresh()->values['common']['prijs']['EUR'])->toBe('800');
});

/**
 * The confirmation threshold is a prompt in the admin editor, NOT a cap: a
 * bigger number has already been confirmed by a human, and quietly shaving it
 * down would produce a price nobody chose.
 */
it('applies a discount above the confirmation threshold in full', function () {
    Queue::fake();

    config()->set('competitor_pricing.manual_discount_confirm_pct', 50);

    $variant = makePricedVariant([
        'prijs'              => ['EUR' => '1000'],
        'adviesverkoopprijs' => ['EUR' => '1000'],
        'extra_korting'      => 70,
    ]);

    app(CompetitorPricingService::class)->recomputeForSkus([$variant->sku]);

    expect($variant->fresh()->values['common']['prijs']['EUR'])->toBe('300');
});

it('refuses a manual discount outside 0-100 instead of pricing a rug at nothing', function (int|string $pct) {
    Queue::fake();

    $variant = makePricedVariant([
        'prijs'              => ['EUR' => '1000'],
        'adviesverkoopprijs' => ['EUR' => '1000'],
        'extra_korting'      => $pct,
    ]);

    app(CompetitorPricingService::class)->recomputeForSkus([$variant->sku]);

    /** Untouched: the advies price, with no discount applied at all. */
    expect($variant->fresh()->values['common']['prijs']['EUR'])->toBe('1000');
})->with([100, 150, -10, 0, '', 'tien']);

it('records the manual discount in the price history so a cheap rug is explainable', function () {
    Queue::fake();

    $variant = makePricedVariant([
        'prijs'              => ['EUR' => '1000'],
        'adviesverkoopprijs' => ['EUR' => '1000'],
        'extra_korting'      => 30,
    ]);

    app(CompetitorPricingService::class)->recomputeForSkus([$variant->sku]);

    $history = ProductPriceHistory::where('sku', $variant->sku)->first();

    expect($history)->not->toBeNull()
        ->and((float) $history->new_price)->toBe(700.0)
        ->and($history->reason)->toContain('handmatige extra korting')
        ->and($history->reason)->toContain('30%');
});

/**
 * Saving the percentage does not write `prijs` — CompetitorPricingService owns
 * that field — so without an immediate recompute the shop and Bol keep serving
 * the old price until the nightly run, and the discount looks like it silently
 * did nothing.
 */
it('reprices and syncs straight away when the extra discount is saved in the editor', function () {
    Queue::fake();

    $variant = makePricedVariant([
        'prijs'              => ['EUR' => '1000'],
        'adviesverkoopprijs' => ['EUR' => '1000'],
        'onderkleed'         => 'Zonder onderkleed',
    ]);

    $this->actingAs(Webkul\User\Models\Admin::first(), 'admin')
        ->post(route('admin.tools.product-stock-editor.post'), [
            'product' => [
                $variant->id => [
                    'voorraad_eurogros'            => '',
                    'voorraad_5_korting_handmatig' => '',
                    'voorraad_hw_5_korting'        => '',
                    'uitverkoop_15_korting'        => '',
                    'extra_korting'                => '20',
                ],
            ],
        ])
        ->assertRedirect();

    expect($variant->fresh()->values['common']['extra_korting'])->toBe(20)
        ->and($variant->fresh()->values['common']['prijs']['EUR'])->toBe('800');

    Queue::assertPushed(SerializedProcessProductsToWooCommerce::class);
});

it('does not repeatedly reprice when the editor is saved without touching the discount', function () {
    Queue::fake();

    $variant = makePricedVariant([
        'prijs'              => ['EUR' => '1000'],
        'adviesverkoopprijs' => ['EUR' => '1000'],
        'onderkleed'         => 'Zonder onderkleed',
    ]);

    $this->actingAs(Webkul\User\Models\Admin::first(), 'admin')
        ->post(route('admin.tools.product-stock-editor.post'), [
            'product' => [
                $variant->id => [
                    'voorraad_eurogros'            => '5',
                    'voorraad_5_korting_handmatig' => '',
                    'voorraad_hw_5_korting'        => '',
                    'uitverkoop_15_korting'        => '',
                    'extra_korting'                => '',
                ],
            ],
        ])
        ->assertRedirect();

    /** Stock changed, price did not: no discount, so no price history row. */
    expect($variant->fresh()->values['common']['prijs']['EUR'])->toBe('1000')
        ->and(ProductPriceHistory::where('sku', $variant->sku)->count())->toBe(0);
});
