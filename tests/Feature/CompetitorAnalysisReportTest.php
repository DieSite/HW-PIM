<?php

use App\Mail\CompetitorAnalysisReport;
use App\Models\CompetitorCoverageConfirmation;
use App\Models\CompetitorPrice;
use App\Models\CompetitorPriceRemoval;
use App\Models\CompetitorSignalReview;
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
    CompetitorPriceRemoval::where('sku', 'like', 'CARTEST-%')->delete();
    CompetitorCoverageConfirmation::where('sku', 'like', 'CARTEST-%')->delete();
    CompetitorSignalReview::where('sku', 'like', 'CARTEST-%')->delete();
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
            && str_contains($mail->envelope()->subject, 'Concurrentie-analyse vloerkleden');
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
        // Het kleed staat op 30% van zijn adviesprijs: dat is het signaal dat
        // de top-5 hoort op te pikken, mét de reden en de vervolgstap erbij.
        ->toContain('waarvan de prijs waarschijnlijk niet klopt')
        ->toContain('van het advies')
        ->toContain('Nieuwe kleden in de PIM')
        ->toContain('niet meer bij de concurrent vinden')
        ->toContain('zonder enige concurrentprijs');
});

it('reports no outliers and no attachment when nothing changed', function () {
    $report = app(CompetitorAnalysisReporter::class)->build(now()->subSecond(), now());

    $mail = new CompetitorAnalysisReport($report);

    // Zonder wijzigingen hoort er geen wijzigingen-CSV te zijn. De mail zelf is
    // niet leeg: openstaande acties (kleden zonder concurrent) blijven staan tot
    // iemand ze oplost, dus die horen er ook op een rustige dag in.
    expect($report['changes']['total'])->toBe(0)
        ->and(collect($mail->attachments())->contains(fn ($a): bool => str_contains($a->as, 'prijswijzigingen')))->toBeFalse()
        ->and($mail->render())->toContain('0 prijzen gewijzigd');
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
    $pdo->exec("INSERT INTO prices VALUES ('{$variant->sku}', 'shopa.nl', '€ 850,00', 'https://shopa.nl/kleed', '".now()->toDateTimeString()."')");
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
        ->and(collect($mail->attachments())->pluck('as')->filter(fn (string $as): bool => str_contains($as, 'aandachtspunten')))->toHaveCount(1)
        ->and(app(CompetitorAnalysisReporter::class)->checksToCsv($report['checks']))->toContain('stil.nl')
        ->and($mail->envelope()->subject)->toContain('Concurrentie-analyse');
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

it('counts a price as confirmed while it is still within the refresh cycle', function () {
    config()->set('competitor_pricing.refresh_days', 7);

    // Index shops hand over their whole catalogue every night; the custom shops
    // are fetched page by page and are only due once a week. Judged per run,
    // the four custom rows below look dead on six nights out of seven — which
    // is what put the refresh rate at 25% and called healthy shops silent.
    CompetitorPrice::create(['sku' => 'CARTEST-R1', 'shop' => 'index.nl', 'price' => 500, 'scraped_at' => now()]);
    CompetitorPrice::create(['sku' => 'CARTEST-R2', 'shop' => 'custom.nl', 'price' => 600, 'scraped_at' => now()->subDays(5)]);
    CompetitorPrice::create(['sku' => 'CARTEST-R3', 'shop' => 'custom.nl', 'price' => 610, 'scraped_at' => now()->subDays(5)]);
    CompetitorPrice::create(['sku' => 'CARTEST-R4', 'shop' => 'custom.nl', 'price' => 620, 'scraped_at' => now()->subDays(5)]);

    $report = app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now());

    expect(reportCheck($report, 'refresh_rate')['status'])->toBe('ok')
        ->and(reportCheck($report, 'refresh_rate')['value'])->toContain('4 van 4')
        ->and(reportCheck($report, 'silent_shops')['status'])->toBe('ok')
        ->and(reportCheck($report, 'partial_shops')['status'])->toBe('ok')
        ->and($report['coverage']['fresh'])->toBe(4);
});

it('still alerts when prices go unconfirmed for longer than the refresh cycle', function () {
    config()->set('competitor_pricing.refresh_days', 7);

    CompetitorPrice::create(['sku' => 'CARTEST-S1', 'shop' => 'index.nl', 'price' => 500, 'scraped_at' => now()]);

    // The met-onderkleed leftovers were exactly this: real prices in the table
    // that the scraper stopped visiting months ago.
    foreach (['CARTEST-S2', 'CARTEST-S3', 'CARTEST-S4'] as $sku) {
        CompetitorPrice::create(['sku' => $sku, 'shop' => 'vergeten.nl', 'price' => 600, 'scraped_at' => now()->subDays(84)]);
    }

    $report = app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now());

    expect(reportCheck($report, 'refresh_rate')['status'])->toBe('alert')
        ->and(reportCheck($report, 'refresh_rate')['value'])->toContain('1 van 4')
        ->and(reportCheck($report, 'silent_shops')['items'][0])->toContain('vergeten.nl');
});

it('alerts when the run itself confirmed nothing, even inside the cycle', function () {
    config()->set('competitor_pricing.refresh_days', 7);

    CompetitorPrice::create(['sku' => 'CARTEST-T1', 'shop' => 'custom.nl', 'price' => 500, 'scraped_at' => now()->subDays(2)]);

    $report = app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now());

    // Everything is inside its cycle, so the refresh rate is green — a scrape
    // that died tonight would otherwise stay invisible for a whole week.
    expect(reportCheck($report, 'refresh_rate')['status'])->toBe('ok')
        ->and(reportCheck($report, 'run_confirmed')['status'])->toBe('alert')
        ->and($report['alerts'])->toBeGreaterThan(0);
});

it('expects every price to be confirmed each run by default', function () {
    config()->set('competitor_pricing.refresh_days', 0);

    CompetitorPrice::create(['sku' => 'CARTEST-D1', 'shop' => 'werkt.nl', 'price' => 500, 'scraped_at' => now()]);
    CompetitorPrice::create(['sku' => 'CARTEST-D2', 'shop' => 'achter.nl', 'price' => 600, 'scraped_at' => now()->subDays(5)]);
    CompetitorPrice::create(['sku' => 'CARTEST-D3', 'shop' => 'achter.nl', 'price' => 610, 'scraped_at' => now()->subDays(5)]);
    CompetitorPrice::create(['sku' => 'CARTEST-D4', 'shop' => 'achter.nl', 'price' => 620, 'scraped_at' => now()->subDays(5)]);

    $report = app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now());

    // Zonder cyclus is vijf dagen oud gewoon niet bevestigd: die prijs stuurt
    // onze verkoopprijs terwijl niemand hem sinds vorige week gezien heeft.
    expect(reportCheck($report, 'refresh_rate')['status'])->toBe('alert')
        ->and(reportCheck($report, 'refresh_rate')['value'])->toContain('1 van 4')
        ->and(reportCheck($report, 'refresh_rate')['detail'])->toContain('binnen een dag')
        ->and(reportCheck($report, 'silent_shops')['items'][0])->toContain('achter.nl');
});

it('lists rugs added to the PIM and says what is still missing', function () {
    makeReportFamily([
        ['sku' => 'CARTEST-N1', 'maat' => '200 cm x 300 cm', 'prijs' => 600.0, 'advies' => 600.0],
        ['sku' => 'CARTEST-N2', 'maat' => '160 cm x 230 cm', 'prijs' => 400.0],
    ], 'CARTEST-NIEUW');

    $block = app(CompetitorAnalysisReporter::class)
        ->build(now()->subHour(), now())['actions']['new_products'];

    $items = collect($block['items'])->keyBy('sku');

    expect($block['total'])->toBe(2)
        // Zonder adviesprijs slaat de prijsberekening het kleed over; dat is de
        // enige actie die er bij een nieuw product echt toe doet.
        ->and($items['CARTEST-N2']['actie'])->toContain('Vul de adviesverkoopprijs')
        ->and($items['CARTEST-N1']['actie'])->toContain('Nog geen concurrent gevonden');
});

it('lists rugs that got their first competitor price', function () {
    makeReportFamily([
        ['sku' => 'CARTEST-P1', 'maat' => '200 cm x 300 cm', 'prijs' => 900.0, 'advies' => 1000.0],
    ], 'CARTEST-EERST');

    CompetitorPrice::create(['sku' => 'CARTEST-P1', 'shop' => 'shopa.nl', 'price' => 900, 'url' => 'https://shopa.nl/p1', 'scraped_at' => now()]);
    logReportChange('CARTEST-P1', 1000, 900, 'Concurrent shopa.nl verlaagde naar € 900,00 — nieuwe laagste prijs.', 'shopa.nl', 900);

    $block = app(CompetitorAnalysisReporter::class)
        ->build(now()->subHour(), now())['actions']['new_prices'];

    expect($block['total'])->toBe(1)
        ->and($block['items'][0]['sku'])->toBe('CARTEST-P1')
        ->and($block['items'][0]['shop'])->toBe('shopa.nl')
        ->and($block['items'][0]['actie'])->toContain('Prijs is hierdoor aangepast');
});

it('lists couplings that disappeared and flags the last one lost', function () {
    makeReportFamily([
        ['sku' => 'CARTEST-L1', 'maat' => '200 cm x 300 cm', 'prijs' => 1000.0, 'advies' => 1000.0],
        ['sku' => 'CARTEST-L2', 'maat' => '160 cm x 230 cm', 'prijs' => 500.0, 'advies' => 600.0],
    ], 'CARTEST-KWIJT');

    // L1 raakt zijn enige concurrent kwijt; L2 houdt er nog één over.
    CompetitorPriceRemoval::create(['sku' => 'CARTEST-L1', 'shop' => 'weg.nl', 'price' => 800, 'url' => 'https://weg.nl/l1', 'removed_at' => now()]);
    CompetitorPriceRemoval::create(['sku' => 'CARTEST-L2', 'shop' => 'weg.nl', 'price' => 450, 'removed_at' => now()]);
    CompetitorPrice::create(['sku' => 'CARTEST-L2', 'shop' => 'blijft.nl', 'price' => 500, 'scraped_at' => now()]);

    $block = app(CompetitorAnalysisReporter::class)
        ->build(now()->subHour(), now())['actions']['lost_prices'];

    $items = collect($block['items'])->keyBy('sku');

    expect($block['total'])->toBe(2)
        ->and($items['CARTEST-L1']['actie'])->toContain('Laatste concurrent weg')
        ->and($items['CARTEST-L2']['resterend'])->toBe(1)
        ->and($items['CARTEST-L2']['actie'])->toContain('Nog 1 andere concurrent');
});

it('lists rugs no competitor sells, most expensive first', function () {
    makeReportFamily([
        ['sku' => 'CARTEST-Z1', 'maat' => '200 cm x 300 cm', 'prijs' => 400.0, 'advies' => 400.0],
        ['sku' => 'CARTEST-Z2', 'maat' => '240 cm x 340 cm', 'prijs' => 2000.0, 'advies' => 2000.0],
        ['sku' => 'CARTEST-Z3', 'maat' => 'Maatwerk', 'prijs' => 900.0, 'advies' => 900.0],
        ['sku' => 'CARTEST-Z4', 'maat' => '200 cm x 300 cm', 'prijs' => 1030.0, 'advies' => 1030.0, 'onderkleed' => 'Met onderkleed'],
    ], 'CARTEST-BLIND');

    CompetitorPrice::create(['sku' => 'CARTEST-Z1', 'shop' => 'shopa.nl', 'price' => 380, 'scraped_at' => now()]);

    $block = app(CompetitorAnalysisReporter::class)
        ->build(now()->subHour(), now())['actions']['no_coverage'];

    $skus = collect($block['items'])->pluck('sku');

    // Z1 heeft een concurrent, Z3 is maatwerk en Z4 is een bundel: geen van
    // drieën is met een concurrentpagina te vergelijken.
    expect($skus)->toContain('CARTEST-Z2')
        ->and($skus)->not->toContain('CARTEST-Z1')
        ->and($skus)->not->toContain('CARTEST-Z3')
        ->and($skus)->not->toContain('CARTEST-Z4')
        ->and($block['items'][0]['sku'])->toBe('CARTEST-Z2');
});

it('ranks the suspect prices by how certain the signal is', function () {
    makeReportFamily([
        // Boven het plafond: kan niet uit de prijsberekening komen.
        ['sku' => 'CARTEST-X1', 'maat' => '200 cm x 300 cm', 'prijs' => 1200.0, 'advies' => 1000.0],
        // Concurrent op 30% van de adviesprijs: waarschijnlijk een ander kleed.
        ['sku' => 'CARTEST-X2', 'maat' => '200 cm x 300 cm', 'prijs' => 750.0, 'advies' => 1000.0],
    ], 'CARTEST-VERDACHT');

    CompetitorPrice::create(['sku' => 'CARTEST-X1', 'shop' => 'shopa.nl', 'price' => 1100, 'scraped_at' => now()]);
    CompetitorPrice::create(['sku' => 'CARTEST-X2', 'shop' => 'shopa.nl', 'price' => 300, 'url' => 'https://shopa.nl/x2', 'scraped_at' => now()]);

    $block = app(CompetitorAnalysisReporter::class)
        ->build(now()->subHour(), now())['actions']['suspects'];

    $items = collect($block['items']);

    expect($items->count())->toBeLessThanOrEqual(5)
        ->and($items->pluck('sku')->first())->toBe('CARTEST-X1')
        ->and($items->firstWhere('sku', 'CARTEST-X1')['reden'])->toContain('boven de adviesprijs')
        ->and($items->firstWhere('sku', 'CARTEST-X2')['reden'])->toContain('van het advies')
        ->and($items->firstWhere('sku', 'CARTEST-X2')['url'])->toBe('https://shopa.nl/x2');
});

it('keeps derived bundles out of the suspect top-5', function () {
    // Dezelfde fout op het kale kleed en op zijn bundel: die bundel is niets
    // anders dan het kale kleed plus de toeslag, dus hij hoort geen tweede
    // plek in een lijst van vijf op te eten.
    makeReportFamily([
        ['sku' => 'CARTEST-Y1', 'maat' => '200 cm x 300 cm', 'prijs' => 750.0, 'advies' => 1000.0],
        ['sku' => 'CARTEST-Y1.O', 'maat' => '200 cm x 300 cm', 'prijs' => 780.0, 'advies' => 1030.0, 'onderkleed' => 'Met onderkleed'],
    ], 'CARTEST-BUNDEL');

    CompetitorPrice::create(['sku' => 'CARTEST-Y1', 'shop' => 'shopa.nl', 'price' => 300, 'scraped_at' => now()]);
    CompetitorPrice::create(['sku' => 'CARTEST-Y1.O', 'shop' => 'shopa.nl', 'price' => 300, 'scraped_at' => now()]);

    $block = app(CompetitorAnalysisReporter::class)
        ->build(now()->subHour(), now())['actions']['suspects'];

    $skus = collect($block['items'])->pluck('sku');

    expect($skus)->toContain('CARTEST-Y1')
        ->and($skus)->not->toContain('CARTEST-Y1.O');
});

it('renders the report on the wide mail theme', function () {
    $report = app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now());
    $mail = new CompetitorAnalysisReport($report);

    // Vijf kolommen passen niet in Laravel's 570px; het bredere thema geldt
    // alleen voor deze mail, zodat de overige UnoPim-mails niet meeveranderen.
    expect($mail->theme)->toBe('hw')
        ->and($mail->render())->toContain('1000px')
        ->and($mail->render())->not->toContain('570px');
});

it('names the second competitor when that is what the signal compares against', function () {
    makeReportFamily([
        ['sku' => 'CARTEST-D9', 'maat' => '300 cm x 400 cm', 'prijs' => 1100.0, 'advies' => 1100.0],
    ], 'CARTEST-DISSENT');

    CompetitorPrice::create(['sku' => 'CARTEST-D9', 'shop' => 'goedkoop.nl', 'price' => 1100, 'url' => 'https://goedkoop.nl/d9', 'scraped_at' => now()]);
    CompetitorPrice::create(['sku' => 'CARTEST-D9', 'shop' => 'duur.nl', 'price' => 1800, 'url' => 'https://duur.nl/d9', 'scraped_at' => now()]);

    $report = app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now());
    $row = collect($report['actions']['suspects']['items'])->firstWhere('sku', 'CARTEST-D9');

    // Zonder de tweede pagina is "39% onder de 2e concurrent" niet te
    // controleren: je weet niet waar die 39% onder ligt.
    expect($row['reden'])->toContain('onder de 2e concurrent')
        ->and($row['tweede_shop'])->toBe('duur.nl')
        ->and($row['tweede_url'])->toBe('https://duur.nl/d9')
        ->and($row['tweede_prijs'])->toBe(1800.0)
        ->and((new CompetitorAnalysisReport($report))->render())->toContain('https://duur.nl/d9');
});

it('puts both the actions and the size of the run in the subject', function () {
    makeReportFamily([
        ['sku' => 'CARTEST-S9', 'maat' => '200 cm x 300 cm', 'prijs' => 900.0, 'advies' => 1000.0],
    ], 'CARTEST-SUBJECT');

    logReportChange('CARTEST-S9', 1000, 900, 'Concurrent shopa.nl verlaagde naar € 900,00 — nieuwe laagste prijs.', 'shopa.nl', 900);

    $subject = (new CompetitorAnalysisReport(
        app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now())
    ))->envelope()->subject;

    expect($subject)->toContain('1 prijswijziging')
        ->and($subject)->toContain('nieuw');
});

it('offers a confirm link and a report-url link per rug without competitors', function () {
    makeReportFamily([
        ['sku' => 'CARTEST-K1', 'maat' => '200 cm x 300 cm', 'prijs' => 900.0, 'advies' => 900.0],
    ], 'CARTEST-KNOP');

    $block = app(CompetitorAnalysisReporter::class)
        ->build(now()->subHour(), now())['actions']['no_coverage'];

    $row = collect($block['items'])->firstWhere('sku', 'CARTEST-K1');

    expect($row['bevestig_url'])->toContain('/pricing/geen-concurrent/CARTEST-K1')
        ->and($row['bevestig_url'])->toContain('signature=')
        ->and($row['mail_url'])->toStartWith('mailto:support@diesite.nl')
        ->and(urldecode($row['mail_url']))->toContain('SKU: CARTEST-K1')
        ->and((new CompetitorAnalysisReport(app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now())))->render())
        ->toContain('Klopt, geen concurrent gevonden');
});

it('drops a rug from the no-coverage block once it is confirmed', function () {
    makeReportFamily([
        ['sku' => 'CARTEST-K2', 'maat' => '200 cm x 300 cm', 'prijs' => 900.0, 'advies' => 900.0],
    ], 'CARTEST-BEVESTIG');

    $reporter = app(CompetitorAnalysisReporter::class);
    $voor = $reporter->build(now()->subHour(), now())['actions']['no_coverage'];

    // De knop uit de mail: ondertekend, zonder sessie, want hij wordt vanuit
    // een mailclient geklikt.
    $this->get(collect($voor['items'])->firstWhere('sku', 'CARTEST-K2')['bevestig_url'])
        ->assertOk()
        ->assertSee('CARTEST-K2');

    $na = $reporter->build(now()->subHour(), now())['actions']['no_coverage'];

    expect(collect($voor['items'])->pluck('sku'))->toContain('CARTEST-K2')
        ->and(collect($na['items'])->pluck('sku'))->not->toContain('CARTEST-K2')
        ->and($na['total'])->toBe($voor['total'] - 1);
});

it('refuses an unsigned confirm link', function () {
    makeReportFamily([
        ['sku' => 'CARTEST-K3', 'maat' => '200 cm x 300 cm', 'prijs' => 900.0, 'advies' => 900.0],
    ], 'CARTEST-ONGETEKEND');

    $this->get('/pricing/geen-concurrent/CARTEST-K3')->assertForbidden();

    expect(CompetitorCoverageConfirmation::where('sku', 'CARTEST-K3')->count())->toBe(0);
});

it('offers a prefilled support mail when a coupling looks wrong', function () {
    makeReportFamily([
        ['sku' => 'CARTEST-R9', 'maat' => '200 cm x 300 cm', 'prijs' => 750.0, 'advies' => 1000.0],
    ], 'CARTEST-AFKEUR');

    CompetitorPrice::create(['sku' => 'CARTEST-R9', 'shop' => 'fout.nl', 'price' => 300, 'url' => 'https://fout.nl/ander-kleed', 'scraped_at' => now()]);

    $report = app(CompetitorAnalysisReporter::class)->build(now()->subHour(), now());
    $row = collect($report['actions']['suspects']['items'])->firstWhere('sku', 'CARTEST-R9');

    // De knop doet niets anders dan melden: de prijs blijft staan tot iemand
    // bij support ernaar gekeken heeft.
    $body = urldecode($row['afkeur_url']);

    expect($row['afkeur_url'])->toStartWith('mailto:support@diesite.nl')
        ->and($body)->toContain('SKU: CARTEST-R9')
        ->and($body)->toContain('Gekoppeld aan: fout.nl')
        ->and($body)->toContain('https://fout.nl/ander-kleed')
        ->and($body)->toContain('Signaal uit het rapport:')
        ->and(CompetitorPrice::where('sku', 'CARTEST-R9')->count())->toBe(1);
});

it('stops showing a suspect once it is marked as correct', function () {
    makeReportFamily([
        ['sku' => 'CARTEST-A9', 'maat' => '200 cm x 300 cm', 'prijs' => 750.0, 'advies' => 1000.0],
    ], 'CARTEST-AKKOORD');

    CompetitorPrice::create(['sku' => 'CARTEST-A9', 'shop' => 'shopa.nl', 'price' => 300, 'scraped_at' => now()]);

    $reporter = app(CompetitorAnalysisReporter::class);
    $voor = $reporter->build(now()->subHour(), now())['actions']['suspects'];

    $this->get(collect($voor['items'])->firstWhere('sku', 'CARTEST-A9')['akkoord_url'])->assertOk();

    $na = $reporter->build(now()->subHour(), now())['actions']['suspects'];

    expect(collect($voor['items'])->pluck('sku'))->toContain('CARTEST-A9')
        ->and(collect($na['items'])->pluck('sku'))->not->toContain('CARTEST-A9')
        // De prijs blijft ongemoeid: dit is een oordeel over het signaal, niet
        // over de koppeling.
        ->and(CompetitorPrice::where('sku', 'CARTEST-A9')->count())->toBe(1);
});

it('lets the reviewed-signals list be emptied again', function () {
    makeReportFamily([
        ['sku' => 'CARTEST-C9', 'maat' => '200 cm x 300 cm', 'prijs' => 900.0, 'advies' => 900.0],
    ], 'CARTEST-LEEG');

    CompetitorCoverageConfirmation::create(['sku' => 'CARTEST-C9', 'confirmed_at' => now()->subDays(40)]);
    CompetitorSignalReview::create(['sku' => 'CARTEST-C9', 'shop' => '', 'verdict' => CompetitorSignalReview::VERDICT_CONFIRMED, 'reviewed_at' => now()]);

    // Een oordeel dat niemand meer ziet mag geen signaal eeuwig verbergen.
    $this->artisan('pricing:clear-competitor-reviews', ['--older-than' => 30, '--dry-run' => true])->assertSuccessful();
    expect(CompetitorCoverageConfirmation::where('sku', 'CARTEST-C9')->count())->toBe(1);

    $this->artisan('pricing:clear-competitor-reviews', ['--older-than' => 30])->assertSuccessful();

    expect(CompetitorCoverageConfirmation::where('sku', 'CARTEST-C9')->count())->toBe(0)
        ->and(CompetitorSignalReview::where('sku', 'CARTEST-C9')->count())->toBe(1);

    $this->artisan('pricing:clear-competitor-reviews', ['--sku' => 'CARTEST-C9'])->assertSuccessful();
    expect(CompetitorSignalReview::where('sku', 'CARTEST-C9')->count())->toBe(0);
});
