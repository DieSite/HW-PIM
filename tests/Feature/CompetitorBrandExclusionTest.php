<?php

use App\Models\CompetitorPrice;
use App\Models\CompetitorPriceRemoval;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Services\CompetitorBrandFilter;
use App\Services\CompetitorCatalogExporter;
use App\Services\CompetitorPricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\WooCommerce\Listeners\SerializedProcessProductsToWooCommerce;

/**
 * Brands we no longer sell (De Munk since November 2026) stay in the PIM but
 * leave the competitor analysis entirely: not exported to the scraper, their
 * competitor prices removed, and their selling price left alone.
 */
function makeBrandedVariant(string $merk, string $sku, string $prijs = '1000', string $advies = '1000'): Product
{
    $familyId = DB::table('attribute_families')->value('id')
        ?? DB::table('attribute_families')->insertGetId(['code' => 'fam_'.uniqid(), 'status' => 1]);

    $parent = new Product();
    $parent->attribute_family_id = $familyId;
    $parent->sku = $sku.'-PARENT';
    $parent->type = 'configurable';
    $parent->status = 1;
    $parent->values = ['common' => ['merk' => $merk, 'productnaam' => 'Model']];
    $parent->save();

    $variant = new Product();
    $variant->attribute_family_id = $familyId;
    $variant->parent_id = $parent->id;
    $variant->sku = $sku;
    $variant->type = 'simple';
    $variant->status = 1;
    $variant->values = ['common' => [
        'productnaam'        => 'Model 1',
        'maat'               => '200 cm x 290 cm',
        'prijs'              => ['EUR' => $prijs],
        'adviesverkoopprijs' => ['EUR' => $advies],
    ]];
    $variant->save();

    return $variant->fresh();
}

function makeExclusionScraperDb(array $rows): string
{
    $dbPath = tempnam(sys_get_temp_dir(), 'compdb').'.sqlite';
    $pdo = new PDO('sqlite:'.$dbPath);
    $pdo->exec('CREATE TABLE prices (sku TEXT, shop TEXT, price_str TEXT, url TEXT, scraped_at TEXT)');
    $insert = $pdo->prepare('INSERT INTO prices VALUES (?, ?, ?, ?, ?)');

    foreach ($rows as [$sku, $shop, $priceStr]) {
        $insert->execute([$sku, $shop, $priceStr, null, now()->toDateTimeString()]);
    }

    return $dbPath;
}

beforeEach(function () {
    config(['competitor_pricing.excluded_brands' => ['De Munk']]);
});

afterEach(function () {
    Product::where('sku', 'like', 'CBXTEST-%')->delete();
    CompetitorPrice::where('sku', 'like', 'CBXTEST-%')->delete();
    CompetitorPriceRemoval::where('sku', 'like', 'CBXTEST-%')->delete();
    ProductPriceHistory::where('sku', 'like', 'CBXTEST-%')->delete();
});

it('matches the parent brand as a whole value, case-insensitive', function () {
    config(['competitor_pricing.excluded_brands' => ['de munk', 'Karpi']]);
    $filter = app(CompetitorBrandFilter::class);

    expect($filter->isExcluded(makeBrandedVariant('De Munk', 'CBXTEST-A')))->toBeTrue()
        ->and($filter->isExcluded(makeBrandedVariant('Eurogros', 'CBXTEST-B')))->toBeFalse()
        // "Karpi" excluded must not catch the combined Mart Visser|Karpi brand
        ->and($filter->isExcluded(makeBrandedVariant('Mart Visser|Karpi', 'CBXTEST-C')))->toBeFalse();
});

it('leaves excluded brands out of the scraper catalog', function () {
    makeBrandedVariant('De Munk', 'CBXTEST-DM1');
    makeBrandedVariant('Brinker', 'CBXTEST-BR1');

    $path = tempnam(sys_get_temp_dir(), 'catalog_');
    app(CompetitorCatalogExporter::class)->export($path);
    $csv = file_get_contents($path);
    @unlink($path);

    expect($csv)->toContain('CBXTEST-BR1,Brinker,')
        ->and($csv)->not->toContain('CBXTEST-DM1');
});

it('never moves the selling price of an excluded brand, even with a cheaper competitor', function () {
    Queue::fake();

    $variant = makeBrandedVariant('De Munk', 'CBXTEST-DM2', prijs: '900', advies: '1000');
    CompetitorPrice::create(['sku' => $variant->sku, 'shop' => 'shopa.nl', 'price' => 800, 'url' => 'https://shopa.nl/rug']);

    app(CompetitorPricingService::class)->recomputeForSkus([$variant->sku]);

    expect($variant->fresh()->values['common']['prijs']['EUR'])->toBe('900')
        ->and(ProductPriceHistory::where('sku', $variant->sku)->exists())->toBeFalse();
    Queue::assertNotPushed(SerializedProcessProductsToWooCommerce::class);
});

it('removes stored prices of excluded brands on import without logging, braking or recomputing', function () {
    Queue::fake();
    config(['competitor_pricing.prune_brake' => ['min_rows' => 1, 'max_loss_pct' => 50]]);

    $deMunk = makeBrandedVariant('De Munk', 'CBXTEST-DM3', prijs: '800', advies: '1000');
    $brinker = makeBrandedVariant('Brinker', 'CBXTEST-BR3');

    // A shop that only ever sold De Munk: losing 100% of its rows would trip
    // the prune brake if this went through --prune.
    CompetitorPrice::create(['sku' => $deMunk->sku, 'shop' => 'alleendemunk.nl', 'price' => 800]);

    $db = makeExclusionScraperDb([
        [$deMunk->sku, 'alleendemunk.nl', '€ 800,00'],
        [$brinker->sku, 'vmusvloerkleden.nl', '€ 950,00'],
    ]);

    $this->artisan('pricing:import-competitor-prices', ['--db' => $db, '--prune' => true])->assertSuccessful();

    expect(CompetitorPrice::where('sku', $deMunk->sku)->exists())->toBeFalse()
        ->and(CompetitorPrice::where('sku', $brinker->sku)->value('price'))->toEqual(950)
        // Not reported as "no longer found at the competitor"
        ->and(CompetitorPriceRemoval::where('sku', $deMunk->sku)->exists())->toBeFalse()
        // Not recomputed back to the adviesprijs
        ->and($deMunk->fresh()->values['common']['prijs']['EUR'])->toBe('800')
        // The Brinker variant is priced as usual
        ->and($brinker->fresh()->values['common']['prijs']['EUR'])->toBe('950');
});
