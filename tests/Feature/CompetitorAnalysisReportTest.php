<?php

use App\Mail\CompetitorAnalysisReport;
use App\Models\CompetitorPrice;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Services\CompetitorAnalysisReporter;
use App\Services\CompetitorPricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * A parent + variant with the given common values, under a SKU prefix that
 * cleanup can safely wipe.
 */
function makeReportVariant(array $common, string $sku): Product
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

function logReportChange(string $sku, float $old, float $new, string $reason, ?string $shop = null, ?float $competitorPrice = null): ProductPriceHistory
{
    return ProductPriceHistory::create([
        'product_id'       => Product::where('sku', $sku)->value('id') ?? 0,
        'sku'              => $sku,
        'old_price'        => $old,
        'new_price'        => $new,
        'reason'           => $reason,
        'competitor_shop'  => $shop,
        'competitor_price' => $competitorPrice,
        'changed_at'       => now(),
    ]);
}

beforeEach(function () {
    config()->set('competitor_pricing.report.recipients', ['luuk@diesite.nl', 'hans@huis-en-wonen.nl']);
    config()->set('competitor_pricing.report.outliers', [
        'drop_pct'         => 15,
        'rise_pct'         => 15,
        'competitor_ratio' => 60,
        'stale_days'       => 14,
        'max_rows'         => 25,
    ]);
});

afterEach(function () {
    Product::where('sku', 'like', 'CARTEST-%')->delete();
    CompetitorPrice::where('sku', 'like', 'CARTEST-%')->delete();
    ProductPriceHistory::where('sku', 'like', 'CARTEST-%')->delete();
});

it('summarises what changed in the window and ignores changes outside it', function () {
    logReportChange('CARTEST-A', 1000, 900, 'Concurrent shopa.nl biedt € 900,00 — laagste concurrent.', 'shopa.nl', 900);
    logReportChange('CARTEST-B', 800, 850, 'Concurrent shopb.nl verhoogde naar € 850,00, maar blijft de laagste.', 'shopb.nl', 850);
    logReportChange('CARTEST-C', 700, 750, 'Teruggezet naar adviesprijs (€ 750,00): geen concurrent goedkoper.');
    logReportChange('CARTEST-D', 730, 780, 'Afgeleid van CARTEST-C (zonder onderkleed, € 750,00) + € 30,00 onderkleedtoeslag voor 200x300');

    $old = logReportChange('CARTEST-OLD', 500, 400, 'Concurrent shopa.nl biedt € 400,00 — laagste concurrent.', 'shopa.nl', 400);
    $old->forceFill(['changed_at' => now()->subDays(3)])->save();

    $report = app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now());

    expect($report['changes']['total'])->toBe(4)
        ->and($report['changes']['products'])->toBe(4)
        ->and($report['changes']['down'])->toBe(1)
        ->and($report['changes']['up'])->toBe(3)
        ->and($report['changes']['competitor'])->toBe(2)
        ->and($report['changes']['advies'])->toBe(1)
        ->and($report['changes']['derived'])->toBe(1)
        ->and($report['changes']['total_delta'])->toBe(50.0);
});

it('classifies a real buildReason() string as the kind the report shows', function () {
    $service = app(CompetitorPricingService::class);

    $competitor = new CompetitorPrice(['sku' => 'CARTEST-K', 'shop' => 'shopa.nl', 'price' => 800, 'url' => null]);

    $followed = $service->buildReason(
        advies: 1000, floor: 750, pct: 25, newPrice: 800,
        competitors: collect([$competitor]), previousForSku: [], lowest: $competitor,
    );

    $reverted = $service->buildReason(
        advies: 1000, floor: 750, pct: 25, newPrice: 1000,
        competitors: collect(), previousForSku: [], lowest: null,
    );

    $clamped = $service->buildReason(
        advies: 1000, floor: 750, pct: 25, newPrice: 750,
        competitors: collect([$competitor]), previousForSku: [],
        lowest: new CompetitorPrice(['sku' => 'CARTEST-K', 'shop' => 'shopa.nl', 'price' => 600]),
    );

    logReportChange('CARTEST-K1', 1000, 800, $followed, 'shopa.nl', 800);
    logReportChange('CARTEST-K2', 800, 1000, $reverted);
    logReportChange('CARTEST-K3', 1000, 750, $clamped, 'shopa.nl', 600);

    $report = app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now());

    expect($report['changes']['competitor'])->toBe(2)
        ->and($report['changes']['advies'])->toBe(1)
        ->and($report['changes']['clamped'])->toBe(1)
        ->and($report['outliers']['not_cheapest'])->toHaveCount(1)
        ->and($report['outliers']['lost_coverage'])->toHaveCount(1);
});

it('flags big drops and rises as outliers', function () {
    logReportChange('CARTEST-DROP', 1000, 700, 'Concurrent shopa.nl verlaagde naar € 700,00 — nieuwe laagste prijs.', 'shopa.nl', 700);
    logReportChange('CARTEST-SMALL', 1000, 960, 'Concurrent shopa.nl verlaagde naar € 960,00 — nieuwe laagste prijs.', 'shopa.nl', 960);
    logReportChange('CARTEST-RISE', 700, 1000, 'Teruggezet naar adviesprijs (€ 1.000,00): geen concurrent goedkoper.');

    $report = app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now());

    expect($report['outliers']['drops'])->toHaveCount(1)
        ->and($report['outliers']['drops'][0]['sku'])->toBe('CARTEST-DROP')
        ->and($report['outliers']['rises'])->toHaveCount(1)
        ->and($report['outliers']['rises'][0]['sku'])->toBe('CARTEST-RISE');
});

it('flags a competitor price far below the adviesverkoopprijs as a suspicious coupling', function () {
    makeReportVariant([
        'prijs'              => ['EUR' => '1000'],
        'adviesverkoopprijs' => ['EUR' => '1000'],
    ], 'CARTEST-SUS');

    makeReportVariant([
        'prijs'              => ['EUR' => '1000'],
        'adviesverkoopprijs' => ['EUR' => '1000'],
    ], 'CARTEST-OK');

    CompetitorPrice::create([
        'sku' => 'CARTEST-SUS', 'shop' => 'shopa.nl', 'price' => 300,
        'url' => 'https://shopa.nl/ander-kleed', 'scraped_at' => now(),
    ]);

    CompetitorPrice::create([
        'sku' => 'CARTEST-OK', 'shop' => 'shopa.nl', 'price' => 900,
        'url' => 'https://shopa.nl/kleed', 'scraped_at' => now(),
    ]);

    $suspicious = collect(app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now())['outliers']['suspicious'])
        ->whereIn('sku', ['CARTEST-SUS', 'CARTEST-OK']);

    expect($suspicious)->toHaveCount(1)
        ->and($suspicious->first()['sku'])->toBe('CARTEST-SUS')
        ->and(round($suspicious->first()['ratio']))->toBe(30.0);
});

it('flags cheapest competitor prices the scraper has not confirmed for a while', function () {
    CompetitorPrice::create([
        'sku' => 'CARTEST-STALE', 'shop' => 'shopa.nl', 'price' => 500,
        'url' => null, 'scraped_at' => now()->subDays(40),
    ]);

    CompetitorPrice::create([
        'sku' => 'CARTEST-FRESH', 'shop' => 'shopa.nl', 'price' => 500,
        'url' => null, 'scraped_at' => now(),
    ]);

    $stale = collect(app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now())['outliers']['stale'])
        ->whereIn('sku', ['CARTEST-STALE', 'CARTEST-FRESH']);

    expect($stale)->toHaveCount(1)
        ->and($stale->first()['sku'])->toBe('CARTEST-STALE')
        ->and($stale->first()['age_days'])->toBeGreaterThanOrEqual(39);
});

it('counts how often each competitor drove a price change', function () {
    CompetitorPrice::create(['sku' => 'CARTEST-S1', 'shop' => 'shopa.nl', 'price' => 900, 'scraped_at' => now()]);
    CompetitorPrice::create(['sku' => 'CARTEST-S2', 'shop' => 'shopa.nl', 'price' => 800, 'scraped_at' => now()]);
    CompetitorPrice::create(['sku' => 'CARTEST-S3', 'shop' => 'shopb.nl', 'price' => 700, 'scraped_at' => now()]);

    logReportChange('CARTEST-S1', 1000, 900, 'Concurrent shopa.nl biedt € 900,00 — laagste concurrent.', 'shopa.nl', 900);
    logReportChange('CARTEST-S2', 1000, 800, 'Concurrent shopa.nl biedt € 800,00 — laagste concurrent.', 'shopa.nl', 800);
    logReportChange('CARTEST-S3', 1000, 700, 'Concurrent shopb.nl biedt € 700,00 — laagste concurrent.', 'shopb.nl', 700);

    $shops = collect(app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now())['shops'])
        ->keyBy('shop');

    expect($shops['shopa.nl']['changes'])->toBe(2)
        ->and($shops['shopb.nl']['changes'])->toBe(1)
        ->and(round($shops['shopa.nl']['avg_pct']))->toBe(-15.0);
});

it('mails the report to the configured recipients with a CSV of every change', function () {
    Mail::fake();

    logReportChange('CARTEST-A', 1000, 900, 'Concurrent shopa.nl biedt € 900,00 — laagste concurrent.', 'shopa.nl', 900);

    $this->artisan('pricing:mail-competitor-report', ['--since' => now()->subHour()->toDateTimeString()])
        ->assertSuccessful();

    Mail::assertSent(CompetitorAnalysisReport::class, function (CompetitorAnalysisReport $mail): bool {
        return $mail->hasTo('luuk@diesite.nl')
            && $mail->hasTo('hans@huis-en-wonen.nl')
            && $mail->attachments() !== []
            && str_contains($mail->envelope()->subject, 'prijswijziging');
    });
});

it('renders the report mail without errors, including its outlier tables', function () {
    Mail::fake();

    makeReportVariant([
        'prijs'              => ['EUR' => '700'],
        'adviesverkoopprijs' => ['EUR' => '1000'],
    ], 'CARTEST-SUS');

    CompetitorPrice::create([
        'sku' => 'CARTEST-SUS', 'shop' => 'shopa.nl', 'price' => 300,
        'url' => 'https://shopa.nl/ander-kleed', 'scraped_at' => now()->subDays(40),
    ]);

    logReportChange('CARTEST-SUS', 1000, 700, 'Concurrent shopa.nl verlaagde naar € 300,00 — nieuwe laagste prijs. (begrensd op adviesprijs −25%).', 'shopa.nl', 300);
    logReportChange('CARTEST-B', 700, 1000, 'Teruggezet naar adviesprijs (€ 1.000,00): geen concurrent goedkoper.');

    $report = app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now());

    $html = (new CompetitorAnalysisReport($report))->render();

    expect($html)->toContain('Concurrentie-analyse vloerkleden')
        ->toContain('CARTEST-SUS')
        ->toContain('Grote prijsdalingen')
        ->toContain('Verdacht lage concurrentprijzen')
        ->toContain('Verouderde concurrentprijzen')
        ->toContain('Terug naar de adviesprijs');
});

it('reports no outliers and no attachment when nothing changed', function () {
    $report = app(CompetitorAnalysisReporter::class)->build(now()->subSecond(), now());

    $mail = new CompetitorAnalysisReport($report);

    expect($report['changes']['total'])->toBe(0)
        ->and($mail->attachments())->toBe([])
        ->and($mail->render())->toContain('geen enkele prijs gewijzigd');
});

it('mails the report at the end of the full pipeline run', function () {
    Mail::fake();
    Queue::fake();

    $variant = makeReportVariant([
        'prijs'              => ['EUR' => '1000'],
        'adviesverkoopprijs' => ['EUR' => '1000'],
        'onderkleed'         => 'Zonder onderkleed',
    ], 'CARTEST-PIPE');

    $dbPath = tempnam(sys_get_temp_dir(), 'cartest_').'.sqlite';
    $pdo = new PDO('sqlite:'.$dbPath);
    $pdo->exec('CREATE TABLE prices (sku TEXT, shop TEXT, price_str TEXT, url TEXT, scraped_at TEXT)');
    $pdo->exec("INSERT INTO prices VALUES ('{$variant->sku}', 'shopa.nl', '€ 850,00', 'https://shopa.nl/kleed', '2026-07-29 04:00:00')");
    $pdo = null;

    config()->set('competitor_pricing.db_path', $dbPath);

    try {
        $this->artisan('pricing:run-competitor-analysis', ['--skip-scrape' => true])->assertSuccessful();
    } finally {
        @unlink($dbPath);
    }

    expect((float) $variant->fresh()->values['common']['prijs']['EUR'])->toBe(850.0);

    Mail::assertSent(CompetitorAnalysisReport::class, function (CompetitorAnalysisReport $mail): bool {
        return collect($mail->report['rows'])->contains(fn (array $row): bool => $row['sku'] === 'CARTEST-PIPE');
    });
});

it('skips the report mail when the run did not recompute any price', function () {
    Mail::fake();

    $dbPath = tempnam(sys_get_temp_dir(), 'cartest_').'.sqlite';
    $pdo = new PDO('sqlite:'.$dbPath);
    $pdo->exec('CREATE TABLE prices (sku TEXT, shop TEXT, price_str TEXT, url TEXT, scraped_at TEXT)');
    $pdo->exec("INSERT INTO prices VALUES ('CARTEST-NR', 'shopa.nl', '€ 850,00', 'https://shopa.nl/kleed', '2026-07-29 04:00:00')");
    $pdo = null;

    config()->set('competitor_pricing.db_path', $dbPath);

    try {
        $this->artisan('pricing:run-competitor-analysis', ['--skip-scrape' => true, '--no-recompute' => true])
            ->assertSuccessful();
    } finally {
        @unlink($dbPath);
    }

    Mail::assertNothingSent();
});

it('writes every change to the CSV with its reason and source URL', function () {
    $history = logReportChange('CARTEST-A', 1000, 900, 'Concurrent shopa.nl biedt € 900,00 — laagste concurrent.', 'shopa.nl', 900);
    $history->forceFill(['competitor_url' => 'https://shopa.nl/kleed'])->save();

    $reporter = app(CompetitorAnalysisReporter::class);
    $report = $reporter->build(now()->subHour(), now());

    $csv = $reporter->toCsv($report['rows']);

    expect($csv)->toContain('SKU;"Oude prijs";"Nieuwe prijs"')
        ->toContain('CARTEST-A;1000,00;900,00;-100,00;-10,0;Concurrent;shopa.nl')
        ->toContain('https://shopa.nl/kleed');
});
