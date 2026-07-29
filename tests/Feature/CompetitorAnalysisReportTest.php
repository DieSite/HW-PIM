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

/**
 * A whole model family: one parent with several sized variants, so the checks
 * that compare a rug against its own siblings have something to compare.
 *
 * @param  list<array{sku: string, maat: string, prijs: float|null, advies?: float, onderkleed?: string}>  $variants
 */
function makeReportFamily(array $variants, string $prefix): void
{
    $familyId = DB::table('attribute_families')->value('id')
        ?? DB::table('attribute_families')->insertGetId(['code' => 'fam_'.uniqid(), 'status' => 1]);

    $parent = new Product();
    $parent->attribute_family_id = $familyId;
    $parent->sku = $prefix.'-PARENT';
    $parent->type = 'configurable';
    $parent->status = 1;
    $parent->values = ['common' => []];
    $parent->save();

    foreach ($variants as $definition) {
        $variant = new Product();
        $variant->attribute_family_id = $familyId;
        $variant->parent_id = $parent->id;
        $variant->sku = $definition['sku'];
        $variant->type = 'simple';
        $variant->status = 1;
        $variant->values = ['common' => array_filter([
            'maat'               => $definition['maat'],
            'onderkleed'         => $definition['onderkleed'] ?? 'Zonder onderkleed',
            'prijs'              => $definition['prijs'] === null ? null : ['EUR' => (string) $definition['prijs']],
            'adviesverkoopprijs' => isset($definition['advies']) ? ['EUR' => (string) $definition['advies']] : null,
        ])];
        $variant->save();
    }
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
    config()->set('competitor_pricing.report.checks', [
        'min_refresh_pct'    => 80,
        'shop_partial_pct'   => 50,
        'shop_ratio_low'     => 70,
        'shop_ratio_high'    => 120,
        'dissent_pct'        => 25,
        'psqm_deviation_pct' => 40,
        'flapping_days'      => 5,
        'mass_change_pct'    => 25,
        'max_items'          => 15,
    ]);
});

/**
 * The single check with the given key, so a test can assert on it without
 * depending on the order the checks are built in.
 */
function reportCheck(array $report, string $key): array
{
    return collect($report['checks'])->firstWhere('key', $key);
}

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

it('parses the rug sizes it needs for the price-per-m² checks', function () {
    $reporter = app(CompetitorAnalysisReporter::class);

    expect($reporter->area('200 cm x 300 cm'))->toBe(6.0)
        ->and($reporter->area('160x230'))->toBe(3.68)
        ->and(round((float) $reporter->area('Rond 200 cm'), 2))->toBe(3.14)
        ->and(round((float) $reporter->area('Ovaal 200 cm x 290 cm'), 2))->toBe(4.56)
        ->and($reporter->area('Maatwerk'))->toBeNull()
        ->and($reporter->area('Onbekend'))->toBeNull();
});

it('flags a competitor that disagrees sharply with all the others', function () {
    CompetitorPrice::create(['sku' => 'CARTEST-D1', 'shop' => 'shopa.nl', 'price' => 300, 'url' => 'https://shopa.nl/a', 'scraped_at' => now()]);
    CompetitorPrice::create(['sku' => 'CARTEST-D1', 'shop' => 'shopb.nl', 'price' => 900, 'url' => 'https://shopb.nl/a', 'scraped_at' => now()]);
    CompetitorPrice::create(['sku' => 'CARTEST-D1', 'shop' => 'shopc.nl', 'price' => 920, 'url' => 'https://shopc.nl/a', 'scraped_at' => now()]);

    CompetitorPrice::create(['sku' => 'CARTEST-D2', 'shop' => 'shopa.nl', 'price' => 880, 'url' => 'https://shopa.nl/b', 'scraped_at' => now()]);
    CompetitorPrice::create(['sku' => 'CARTEST-D2', 'shop' => 'shopb.nl', 'price' => 900, 'url' => 'https://shopb.nl/b', 'scraped_at' => now()]);

    $check = reportCheck(app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now()), 'lone_dissenter');

    expect($check['status'])->toBe('warn')
        ->and($check['items'])->toHaveCount(1)
        ->and($check['items'][0])->toContain('CARTEST-D1')
        ->and($check['items'][0])->toContain('shopa.nl');
});

it('flags one competitor page that priced several of our sizes identically', function () {
    makeReportFamily([
        ['sku' => 'CARTEST-P1', 'maat' => '160 cm x 230 cm', 'prijs' => 500.0, 'advies' => 500.0],
        ['sku' => 'CARTEST-P2', 'maat' => '200 cm x 300 cm', 'prijs' => 800.0, 'advies' => 800.0],
    ], 'CARTEST-PAGE');

    CompetitorPrice::create(['sku' => 'CARTEST-P1', 'shop' => 'shopa.nl', 'price' => 450, 'url' => 'https://shopa.nl/kleed', 'scraped_at' => now()]);
    CompetitorPrice::create(['sku' => 'CARTEST-P2', 'shop' => 'shopa.nl', 'price' => 450, 'url' => 'https://shopa.nl/kleed', 'scraped_at' => now()]);

    $check = reportCheck(app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now()), 'shared_price');

    expect($check['status'])->toBe('warn')
        ->and($check['items'])->toHaveCount(1)
        ->and($check['items'][0])->toContain('2 maten');
});

it('does not flag a competitor page that prices each of our sizes differently', function () {
    makeReportFamily([
        ['sku' => 'CARTEST-P1', 'maat' => '160 cm x 230 cm', 'prijs' => 500.0, 'advies' => 500.0],
        ['sku' => 'CARTEST-P2', 'maat' => '200 cm x 300 cm', 'prijs' => 800.0, 'advies' => 800.0],
    ], 'CARTEST-PAGE');

    CompetitorPrice::create(['sku' => 'CARTEST-P1', 'shop' => 'shopa.nl', 'price' => 450, 'url' => 'https://shopa.nl/kleed', 'scraped_at' => now()]);
    CompetitorPrice::create(['sku' => 'CARTEST-P2', 'shop' => 'shopa.nl', 'price' => 700, 'url' => 'https://shopa.nl/kleed', 'scraped_at' => now()]);

    expect(reportCheck(app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now()), 'shared_price')['status'])
        ->toBe('ok');
});

it('flags a variant whose price per m² falls outside its own model family', function () {
    makeReportFamily([
        ['sku' => 'CARTEST-M1', 'maat' => '160 cm x 230 cm', 'prijs' => 368.0, 'advies' => 368.0],
        ['sku' => 'CARTEST-M2', 'maat' => '200 cm x 300 cm', 'prijs' => 600.0, 'advies' => 600.0],
        ['sku' => 'CARTEST-M3', 'maat' => '240 cm x 340 cm', 'prijs' => 816.0, 'advies' => 816.0],
        ['sku' => 'CARTEST-M4', 'maat' => '300 cm x 400 cm', 'prijs' => 240.0, 'advies' => 1200.0],
    ], 'CARTEST-FAM');

    logReportChange('CARTEST-M4', 1200, 240, 'Concurrent shopa.nl verlaagde naar € 240,00 — nieuwe laagste prijs.', 'shopa.nl', 240);

    $check = reportCheck(app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now()), 'price_per_m2');

    expect($check['status'])->toBe('warn')
        ->and($check['items'])->toHaveCount(1)
        ->and($check['items'][0])->toContain('CARTEST-M4');
});

it('leaves a model family alone when every size costs the same per m²', function () {
    makeReportFamily([
        ['sku' => 'CARTEST-M1', 'maat' => '160 cm x 230 cm', 'prijs' => 368.0, 'advies' => 368.0],
        ['sku' => 'CARTEST-M2', 'maat' => '200 cm x 300 cm', 'prijs' => 600.0, 'advies' => 600.0],
        ['sku' => 'CARTEST-M3', 'maat' => '240 cm x 340 cm', 'prijs' => 816.0, 'advies' => 816.0],
    ], 'CARTEST-FAM');

    logReportChange('CARTEST-M2', 620, 600, 'Concurrent shopa.nl verlaagde naar € 600,00 — nieuwe laagste prijs.', 'shopa.nl', 600);

    expect(reportCheck(app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now()), 'price_per_m2')['status'])
        ->toBe('ok');
});

it('flags a met-onderkleed variant that is not more expensive than the bare one', function () {
    makeReportFamily([
        ['sku' => 'CARTEST-B1', 'maat' => '200 cm x 300 cm', 'prijs' => 600.0, 'advies' => 600.0],
        ['sku' => 'CARTEST-B1.O', 'maat' => '200 cm x 300 cm', 'prijs' => 580.0, 'advies' => 630.0, 'onderkleed' => 'Met onderkleed'],
    ], 'CARTEST-BUN');

    logReportChange('CARTEST-B1', 620, 600, 'Concurrent shopa.nl verlaagde naar € 600,00 — nieuwe laagste prijs.', 'shopa.nl', 600);

    $check = reportCheck(app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now()), 'bundle_price');

    expect($check['status'])->toBe('warn')
        ->and($check['items'])->toHaveCount(1)
        ->and($check['items'][0])->toContain('CARTEST-B1.O');
});

it('raises an alert when a price sits above its adviesverkoopprijs', function () {
    makeReportFamily([
        ['sku' => 'CARTEST-C1', 'maat' => '200 cm x 300 cm', 'prijs' => 900.0, 'advies' => 800.0],
    ], 'CARTEST-CEIL');

    logReportChange('CARTEST-C1', 800, 900, 'Concurrent shopa.nl biedt € 900,00 — laagste concurrent.', 'shopa.nl', 900);

    $check = reportCheck(app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now()), 'above_ceiling');

    expect($check['status'])->toBe('alert')
        ->and($check['items'][0])->toContain('CARTEST-C1');
});

it('raises an alert for a competitor that delivered nothing this run', function () {
    CompetitorPrice::create(['sku' => 'CARTEST-F1', 'shop' => 'werkt.nl', 'price' => 500, 'scraped_at' => now()]);
    CompetitorPrice::create(['sku' => 'CARTEST-F2', 'shop' => 'werkt.nl', 'price' => 600, 'scraped_at' => now()]);
    CompetitorPrice::create(['sku' => 'CARTEST-F3', 'shop' => 'stil.nl', 'price' => 700, 'scraped_at' => now()->subDays(30)]);

    $report = app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now());
    $check = reportCheck($report, 'silent_shops');

    expect($check['status'])->toBe('alert')
        ->and($check['items'])->toHaveCount(1)
        ->and($check['items'][0])->toContain('stil.nl')
        ->and($report['alerts'])->toBeGreaterThan(0);
});

it('reports every check as green when nothing is wrong', function () {
    makeReportFamily([
        ['sku' => 'CARTEST-G1', 'maat' => '160 cm x 230 cm', 'prijs' => 368.0, 'advies' => 400.0],
        ['sku' => 'CARTEST-G2', 'maat' => '200 cm x 300 cm', 'prijs' => 600.0, 'advies' => 650.0],
        ['sku' => 'CARTEST-G3', 'maat' => '240 cm x 340 cm', 'prijs' => 816.0, 'advies' => 850.0],
        ['sku' => 'CARTEST-G4', 'maat' => '240 cm x 240 cm', 'prijs' => 576.0, 'advies' => 600.0],
        ['sku' => 'CARTEST-G5', 'maat' => '140 cm x 200 cm', 'prijs' => 280.0, 'advies' => 300.0],
    ], 'CARTEST-GREEN');

    foreach (['CARTEST-G1' => 340, 'CARTEST-G2' => 560, 'CARTEST-G3' => 780] as $sku => $price) {
        CompetitorPrice::create(['sku' => $sku, 'shop' => 'shopa.nl', 'price' => $price, 'url' => 'https://shopa.nl/'.$sku, 'scraped_at' => now()]);
    }

    logReportChange('CARTEST-G2', 610, 600, 'Concurrent shopa.nl verlaagde naar € 560,00 — nieuwe laagste prijs.', 'shopa.nl', 560);

    $report = app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now());

    expect($report['alerts'])->toBe(0)
        ->and($report['warnings'])->toBe(0)
        ->and($report['flagged'])->toBe(0)
        ->and(collect($report['checks'])->pluck('status')->unique()->all())->toBe(['ok'])
        ->and((new CompetitorAnalysisReport($report))->render())->toContain('controles staan op groen');
});

it('attaches the findings of the checks as a second CSV', function () {
    CompetitorPrice::create(['sku' => 'CARTEST-F1', 'shop' => 'werkt.nl', 'price' => 500, 'scraped_at' => now()]);
    CompetitorPrice::create(['sku' => 'CARTEST-F3', 'shop' => 'stil.nl', 'price' => 700, 'scraped_at' => now()->subDays(30)]);

    $report = app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now());
    $mail = new CompetitorAnalysisReport($report);

    expect($report['flagged'])->toBeGreaterThan(0)
        ->and($mail->attachments())->toHaveCount(1)
        ->and(app(CompetitorAnalysisReporter::class)->checksToCsv($report['checks']))->toContain('stil.nl')
        ->and($mail->envelope()->subject)->toContain('alarm');
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
