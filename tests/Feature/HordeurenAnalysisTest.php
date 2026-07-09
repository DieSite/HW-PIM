<?php

use App\Jobs\RunHordeurenAnalysisJob;
use App\Mail\HordeurenAnalysisFailed;
use App\Mail\HordeurenAnalysisReport;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Webkul\User\Models\Admin;

/**
 * Point the job at a throwaway scraper directory that already has the
 * Playwright test runner, so the npm install step is skipped.
 */
function fakeScraperDir(): string
{
    $dir = sys_get_temp_dir().'/hordeuren-analyse-test-'.uniqid();

    File::makeDirectory($dir.'/node_modules/@playwright/test', 0755, true);

    config()->set('competitor_pricing.scraper_dir', $dir);
    config()->set('competitor_pricing.hordeuren.browsers_path', $dir.'/browsers');
    config()->set('competitor_pricing.hordeuren.output', $dir.'/prijsvergelijking-plisse-hordeuren.xlsx');
    config()->set('competitor_pricing.hordeuren.results', $dir.'/results.json');

    $GLOBALS['hordeuren_test_dirs'][] = $dir;

    return $dir;
}

/**
 * The job invokes node/npx/npm through an absolute, pinned toolchain path, so
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
    \Illuminate\Support\Facades\DB::table('jobs')->insert([
        'queue'        => 'default',
        'payload'      => json_encode(['displayName' => RunHordeurenAnalysisJob::class]),
        'attempts'     => 0,
        'reserved_at'  => null,
        'available_at' => time(),
        'created_at'   => time(),
    ]);

    $this->actingAs(Admin::first(), 'admin')
        ->get(route('admin.tools.hordeuren-analyse.index'))
        ->assertOk()
        ->assertSee('in de wachtrij');
});

it('shows no running notice when the queue is empty', function () {
    $this->actingAs(Admin::first(), 'admin')
        ->get(route('admin.tools.hordeuren-analyse.index'))
        ->assertOk()
        ->assertDontSee('in de wachtrij');
});

it('dispatches the analysis job to the entered email', function () {
    Queue::fake();

    $this->actingAs(Admin::first(), 'admin')
        ->post(route('admin.tools.hordeuren-analyse.run'), ['email' => 'rapport@voorbeeld.nl'])
        ->assertRedirect(route('admin.tools.hordeuren-analyse.index'));

    Queue::assertPushed(
        RunHordeurenAnalysisJob::class,
        fn (RunHordeurenAnalysisJob $job) => $job->email === 'rapport@voorbeeld.nl'
    );
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

it('runs the playwright suite and mails the report', function () {
    Process::fake();
    Mail::fake();

    $dir = fakeScraperDir();

    file_put_contents($dir.'/results.json', json_encode([
        'shop-a.nl' => ['Enkele klein' => '€ 100,00', 'Enkele groot' => 'n.v.t.'],
        'shop-b.nl' => ['Enkele klein' => 'Op aanvraag'],
    ]));
    touch($dir.'/prijsvergelijking-plisse-hordeuren.xlsx', time() + 60);

    (new RunHordeurenAnalysisJob('rapport@voorbeeld.nl'))->handle();

    Process::assertRan(fn ($process) => fakedCommand($process) === 'npx playwright test'
        && ($process->environment['RESET_RESULTS'] ?? null) === '1');

    Mail::assertSent(HordeurenAnalysisReport::class, function (HordeurenAnalysisReport $mail) {
        return $mail->hasTo('rapport@voorbeeld.nl')
            && $mail->summary === ['shops' => 2, 'cells' => 3, 'priced' => 1, 'missing' => 65]
            && ! $mail->hadFailures;
    });

    Process::assertRanTimes(
        fn ($process) => fakedCommand($process) === 'npx playwright test',
        times: 1
    );
});

it('retries with sticky gap-filling passes until the suite is clean', function () {
    Process::fake([
        '*playwright*test*' => Process::sequence()
            ->push(Process::result(output: '', errorOutput: '2 failed', exitCode: 1))
            ->push(Process::result()),
        '*' => Process::result(),
    ]);
    Mail::fake();

    $dir = fakeScraperDir();
    touch($dir.'/prijsvergelijking-plisse-hordeuren.xlsx', time() + 60);

    (new RunHordeurenAnalysisJob('rapport@voorbeeld.nl'))->handle();

    Process::assertRanTimes(
        fn ($process) => fakedCommand($process) === 'npx playwright test',
        times: 2
    );

    Process::assertRan(fn ($process) => fakedCommand($process) === 'npx playwright test'
        && ($process->environment['RESET_RESULTS'] ?? null) === '1');

    Process::assertRan(fn ($process) => fakedCommand($process) === 'npx playwright test'
        && ! array_key_exists('RESET_RESULTS', $process->environment ?? []));

    Mail::assertSent(
        HordeurenAnalysisReport::class,
        fn (HordeurenAnalysisReport $mail) => ! $mail->hadFailures
    );
});

it('gives up gap-filling after the configured number of passes', function () {
    config()->set('competitor_pricing.hordeuren.max_passes', 3);

    Process::fake([
        '*playwright*test*' => Process::result(output: '', errorOutput: '1 failed', exitCode: 1),
        '*'                 => Process::result(),
    ]);
    Mail::fake();

    $dir = fakeScraperDir();
    touch($dir.'/prijsvergelijking-plisse-hordeuren.xlsx', time() + 60);

    (new RunHordeurenAnalysisJob('rapport@voorbeeld.nl'))->handle();

    Process::assertRanTimes(
        fn ($process) => fakedCommand($process) === 'npx playwright test',
        times: 3
    );

    Mail::assertSent(
        HordeurenAnalysisReport::class,
        fn (HordeurenAnalysisReport $mail) => $mail->hadFailures
    );
});

it('flags partial scrape failures in the report mail', function () {
    Process::fake([
        '*playwright*test*' => Process::result(output: '', errorOutput: '1 failed', exitCode: 1),
        '*'                 => Process::result(),
    ]);
    Mail::fake();

    $dir = fakeScraperDir();
    touch($dir.'/prijsvergelijking-plisse-hordeuren.xlsx', time() + 60);

    (new RunHordeurenAnalysisJob('rapport@voorbeeld.nl'))->handle();

    Mail::assertSent(
        HordeurenAnalysisReport::class,
        fn (HordeurenAnalysisReport $mail) => $mail->hadFailures
    );
});

it('installs dependencies when missing and always refreshes chromium', function () {
    Process::fake();
    Mail::fake();

    $dir = fakeScraperDir();
    File::deleteDirectory($dir.'/node_modules');
    touch($dir.'/prijsvergelijking-plisse-hordeuren.xlsx', time() + 60);

    (new RunHordeurenAnalysisJob('rapport@voorbeeld.nl'))->handle();

    Process::assertRan(fn ($process) => fakedCommand($process) === 'npm install');
    Process::assertRan(fn ($process) => fakedCommand($process) === 'npx playwright install chromium');
});

it('installs chromium without sudo-requiring system deps by default', function () {
    Process::fake();
    Mail::fake();

    $dir = fakeScraperDir();
    touch($dir.'/prijsvergelijking-plisse-hordeuren.xlsx', time() + 60);

    (new RunHordeurenAnalysisJob('rapport@voorbeeld.nl'))->handle();

    Process::assertRan(fn ($process) => fakedCommand($process) === 'npx playwright install chromium');
    Process::assertNotRan(fn ($process) => str_contains(fakedCommand($process), '--with-deps'));
});

it('adds --with-deps only when install_deps is enabled', function () {
    config()->set('competitor_pricing.hordeuren.install_deps', true);

    Process::fake();
    Mail::fake();

    $dir = fakeScraperDir();
    touch($dir.'/prijsvergelijking-plisse-hordeuren.xlsx', time() + 60);

    (new RunHordeurenAnalysisJob('rapport@voorbeeld.nl'))->handle();

    Process::assertRan(fn ($process) => fakedCommand($process) === 'npx playwright install --with-deps chromium');
});

it('pins the configured node toolchain and prepends it to PATH', function () {
    config()->set('competitor_pricing.hordeuren.node_bin', '/usr/local/node-24/bin');

    Process::fake();
    Mail::fake();

    $dir = fakeScraperDir();
    touch($dir.'/prijsvergelijking-plisse-hordeuren.xlsx', time() + 60);

    (new RunHordeurenAnalysisJob('rapport@voorbeeld.nl'))->handle();

    Process::assertRan(fn ($process) => ((array) $process->command)[0] === '/usr/local/node-24/bin/npx'
        && str_starts_with((string) ($process->environment['PATH'] ?? ''), '/usr/local/node-24/bin:'));
});

it('throws and mails a failure notice when no report is produced', function () {
    Process::fake();
    Mail::fake();

    fakeScraperDir();

    $job = new RunHordeurenAnalysisJob('rapport@voorbeeld.nl');

    expect(fn () => $job->handle())->toThrow(RuntimeException::class);

    $job->failed(new RuntimeException('boom'));

    Mail::assertSent(
        HordeurenAnalysisFailed::class,
        fn (HordeurenAnalysisFailed $mail) => $mail->hasTo('rapport@voorbeeld.nl') && str_contains($mail->error, 'boom')
    );
});
