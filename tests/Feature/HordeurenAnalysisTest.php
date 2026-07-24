<?php

use App\Jobs\MailHordeurenAnalysisReportJob;
use App\Jobs\RunHordeurenAnalysisJob;
use App\Jobs\ScrapeHordeurenCompetitorJob;
use App\Mail\HordeurenAnalysisFailed;
use App\Mail\HordeurenAnalysisReport;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\PendingBatchFake;
use Webkul\User\Models\Admin;

/**
 * Point the jobs at a throwaway scraper directory that already has the
 * Playwright test runner (so the npm install step is skipped) and two
 * competitor specs.
 */
function fakeScraperDir(array $specs = ['01-voorbeeld.spec.js', '02-ander.spec.js']): string
{
    $dir = sys_get_temp_dir().'/hordeuren-analyse-test-'.uniqid();

    File::makeDirectory($dir.'/node_modules/@playwright/test', 0755, true);
    File::makeDirectory($dir.'/tests', 0755, true);

    foreach ($specs as $spec) {
        file_put_contents($dir.'/tests/'.$spec, '// spec');
    }

    config()->set('competitor_pricing.scraper_dir', $dir);
    config()->set('competitor_pricing.hordeuren.browsers_path', $dir.'/browsers');
    config()->set('competitor_pricing.hordeuren.output', $dir.'/prijsvergelijking-plisse-hordeuren.xlsx');
    config()->set('competitor_pricing.hordeuren.results', $dir.'/results.json');

    $GLOBALS['hordeuren_test_dirs'][] = $dir;

    return $dir;
}

/**
 * The jobs invoke node/npx/npm through an absolute, pinned toolchain path, so
 * compare commands by the binary's basename to stay independent of node_bin.
 */
function fakedCommand($process): string
{
    $parts = (array) $process->command;
    $parts[0] = basename((string) ($parts[0] ?? ''));

    return implode(' ', $parts);
}

afterEach(function () {
    foreach ($GLOBALS['hordeuren_test_dirs'] ?? [] as $dir) {
        File::deleteDirectory($dir);
    }

    $GLOBALS['hordeuren_test_dirs'] = [];
});

it('shows the analysis form with the admin email prefilled', function () {
    $admin = Admin::first();

    $this->actingAs($admin, 'admin')
        ->get(route('admin.tools.hordeuren-analyse.index'))
        ->assertOk()
        ->assertSee('Start analyse')
        ->assertSee($admin->email);
});

it('shows a notice while an analysis is queued or running', function () {
    Cache::put(RunHordeurenAnalysisJob::RUNNING_CACHE_KEY, now()->toIso8601String(), 600);

    $this->actingAs(Admin::first(), 'admin')
        ->get(route('admin.tools.hordeuren-analyse.index'))
        ->assertOk()
        ->assertSee('in de wachtrij');
});

it('shows no running notice when no analysis is active', function () {
    Cache::forget(RunHordeurenAnalysisJob::RUNNING_CACHE_KEY);

    $this->actingAs(Admin::first(), 'admin')
        ->get(route('admin.tools.hordeuren-analyse.index'))
        ->assertOk()
        ->assertDontSee('in de wachtrij');
});

it('dispatches the analysis job to the entered email and flags the run', function () {
    Queue::fake();

    $this->actingAs(Admin::first(), 'admin')
        ->post(route('admin.tools.hordeuren-analyse.run'), ['email' => 'rapport@voorbeeld.nl'])
        ->assertRedirect(route('admin.tools.hordeuren-analyse.index'));

    Queue::assertPushed(
        RunHordeurenAnalysisJob::class,
        fn (RunHordeurenAnalysisJob $job) => $job->email === 'rapport@voorbeeld.nl'
    );

    expect(Cache::get(RunHordeurenAnalysisJob::RUNNING_CACHE_KEY))->not->toBeNull();
});

it('rejects an invalid email address', function () {
    Queue::fake();

    $this->actingAs(Admin::first(), 'admin')
        ->from(route('admin.tools.hordeuren-analyse.index'))
        ->post(route('admin.tools.hordeuren-analyse.run'), ['email' => 'geen-email'])
        ->assertRedirect(route('admin.tools.hordeuren-analyse.index'))
        ->assertSessionHasErrors('email');

    Queue::assertNothingPushed();
});

it('requires an authenticated admin', function () {
    Queue::fake();

    $this->post(route('admin.tools.hordeuren-analyse.run'), ['email' => 'rapport@voorbeeld.nl'])
        ->assertRedirect();

    Queue::assertNothingPushed();
});

it('runs every hordeuren job on the dedicated connection and queue', function () {
    $jobs = [
        new RunHordeurenAnalysisJob('rapport@voorbeeld.nl'),
        new ScrapeHordeurenCompetitorJob('01-voorbeeld.spec.js'),
        new MailHordeurenAnalysisReportJob('rapport@voorbeeld.nl', now()),
    ];

    foreach ($jobs as $job) {
        expect($job->connection)->toBe('redis-hordeuren');
        expect($job->queue)->toBe('hordeuren');
    }
});

it('prepares the toolchain and dispatches one scrape job per competitor spec', function () {
    Bus::fake();
    Process::fake();

    $dir = fakeScraperDir(['01-a.spec.js', '02-b.spec.js', '03-c.spec.js']);

    File::makeDirectory($dir.'/results-parts');
    file_put_contents($dir.'/results-parts/oud.json', '{}');

    (new RunHordeurenAnalysisJob('rapport@voorbeeld.nl'))->handle();

    expect(is_dir($dir.'/results-parts'))->toBeFalse();

    Bus::assertBatched(function (PendingBatchFake $batch) {
        return $batch->name === 'hordeuren-analyse'
            && $batch->jobs->count() === 3
            && $batch->jobs->every(fn ($job) => $job instanceof ScrapeHordeurenCompetitorJob);
    });
});

it('throws when the scraper has no competitor specs', function () {
    Bus::fake();
    Process::fake();

    fakeScraperDir(specs: []);

    expect(fn () => (new RunHordeurenAnalysisJob('rapport@voorbeeld.nl'))->handle())
        ->toThrow(RuntimeException::class, 'No competitor specs');

    Bus::assertNothingBatched();
});

it('installs dependencies when missing and always refreshes chromium', function () {
    Bus::fake();
    Process::fake();

    $dir = fakeScraperDir();
    File::deleteDirectory($dir.'/node_modules');

    (new RunHordeurenAnalysisJob('rapport@voorbeeld.nl'))->handle();

    Process::assertRan(fn ($process) => fakedCommand($process) === 'npm install');
    Process::assertRan(fn ($process) => fakedCommand($process) === 'npx playwright install chromium');
});

it('installs chromium without sudo-requiring system deps by default', function () {
    Bus::fake();
    Process::fake();

    fakeScraperDir();

    (new RunHordeurenAnalysisJob('rapport@voorbeeld.nl'))->handle();

    Process::assertRan(fn ($process) => fakedCommand($process) === 'npx playwright install chromium');
    Process::assertNotRan(fn ($process) => str_contains(fakedCommand($process), '--with-deps'));
});

it('adds --with-deps only when install_deps is enabled', function () {
    config()->set('competitor_pricing.hordeuren.install_deps', true);

    Bus::fake();
    Process::fake();

    fakeScraperDir();

    (new RunHordeurenAnalysisJob('rapport@voorbeeld.nl'))->handle();

    Process::assertRan(fn ($process) => fakedCommand($process) === 'npx playwright install --with-deps chromium');
});

it('pins the configured node toolchain and prepends it to PATH', function () {
    config()->set('competitor_pricing.hordeuren.node_bin', '/usr/local/node-24/bin');

    Bus::fake();
    Process::fake();

    $dir = fakeScraperDir();
    File::deleteDirectory($dir.'/node_modules');

    (new RunHordeurenAnalysisJob('rapport@voorbeeld.nl'))->handle();

    Process::assertRan(fn ($process) => ((array) $process->command)[0] === '/usr/local/node-24/bin/npm'
        && str_starts_with((string) ($process->environment['PATH'] ?? ''), '/usr/local/node-24/bin:'));
});

it('mails a failure notice and clears the running flag when preparation fails', function () {
    Mail::fake();
    Cache::put(RunHordeurenAnalysisJob::RUNNING_CACHE_KEY, now()->toIso8601String(), 600);

    (new RunHordeurenAnalysisJob('rapport@voorbeeld.nl'))->failed(new RuntimeException('boom'));

    expect(Cache::get(RunHordeurenAnalysisJob::RUNNING_CACHE_KEY))->toBeNull();

    Mail::assertSent(
        HordeurenAnalysisFailed::class,
        fn (HordeurenAnalysisFailed $mail) => $mail->hasTo('rapport@voorbeeld.nl') && str_contains($mail->error, 'boom')
    );
});

it('scrapes a single competitor spec', function () {
    Process::fake();

    fakeScraperDir();

    (new ScrapeHordeurenCompetitorJob('01-a.spec.js'))->handle();

    Process::assertRan(fn ($process) => fakedCommand($process) === 'npx playwright test tests/01-a.spec.js');
});

it('throws so the scrape is retried when the spec leaves empty cells', function () {
    Process::fake([
        '*playwright*test*' => Process::result(output: '', errorOutput: '2 failed', exitCode: 1),
    ]);

    fakeScraperDir();

    expect(fn () => (new ScrapeHordeurenCompetitorJob('01-a.spec.js'))->handle())
        ->toThrow(RuntimeException::class, '01-a.spec.js');
});

it('mails the report with a summary and clears the running flag', function () {
    Mail::fake();
    Cache::put(RunHordeurenAnalysisJob::RUNNING_CACHE_KEY, now()->toIso8601String(), 600);

    $dir = fakeScraperDir();

    file_put_contents($dir.'/results.json', json_encode([
        'shop-a.nl' => ['Enkele klein' => '€ 100,00', 'Enkele groot' => 'n.v.t.'],
        'shop-b.nl' => ['Enkele klein' => 'Op aanvraag'],
    ]));
    touch($dir.'/prijsvergelijking-plisse-hordeuren.xlsx', time() + 60);

    (new MailHordeurenAnalysisReportJob('rapport@voorbeeld.nl', now()))->handle();

    Mail::assertSent(HordeurenAnalysisReport::class, function (HordeurenAnalysisReport $mail) {
        return $mail->hasTo('rapport@voorbeeld.nl')
            && $mail->summary === ['shops' => 2, 'cells' => 3, 'priced' => 1, 'missing' => 65]
            && $mail->hadFailures;
    });

    expect(Cache::get(RunHordeurenAnalysisJob::RUNNING_CACHE_KEY))->toBeNull();
});

it('reports a clean run when every size cell is filled and no scrape failed', function () {
    Mail::fake();

    $dir = fakeScraperDir();

    $sizes = (new ReflectionClass(MailHordeurenAnalysisReportJob::class))->getConstant('SIZES');

    file_put_contents($dir.'/results.json', json_encode([
        'shop-a.nl' => array_fill_keys($sizes, '€ 100,00'),
    ]));
    touch($dir.'/prijsvergelijking-plisse-hordeuren.xlsx', time() + 60);

    (new MailHordeurenAnalysisReportJob('rapport@voorbeeld.nl', now(), failedScrapes: 0))->handle();

    Mail::assertSent(
        HordeurenAnalysisReport::class,
        fn (HordeurenAnalysisReport $mail) => ! $mail->hadFailures && $mail->summary['missing'] === 0
    );
});

it('flags the report when competitor scrapes failed', function () {
    Mail::fake();

    $dir = fakeScraperDir();

    $sizes = (new ReflectionClass(MailHordeurenAnalysisReportJob::class))->getConstant('SIZES');

    file_put_contents($dir.'/results.json', json_encode([
        'shop-a.nl' => array_fill_keys($sizes, '€ 100,00'),
    ]));
    touch($dir.'/prijsvergelijking-plisse-hordeuren.xlsx', time() + 60);

    (new MailHordeurenAnalysisReportJob('rapport@voorbeeld.nl', now(), failedScrapes: 2))->handle();

    Mail::assertSent(
        HordeurenAnalysisReport::class,
        fn (HordeurenAnalysisReport $mail) => $mail->hadFailures
    );
});

it('throws and mails a failure notice when no report is produced', function () {
    Mail::fake();

    fakeScraperDir();

    $job = new MailHordeurenAnalysisReportJob('rapport@voorbeeld.nl', now());

    expect(fn () => $job->handle())->toThrow(RuntimeException::class);

    $job->failed(new RuntimeException('boom'));

    Mail::assertSent(
        HordeurenAnalysisFailed::class,
        fn (HordeurenAnalysisFailed $mail) => $mail->hasTo('rapport@voorbeeld.nl') && str_contains($mail->error, 'boom')
    );
});
