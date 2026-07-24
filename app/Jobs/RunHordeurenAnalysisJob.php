<?php

namespace App\Jobs;

use App\Jobs\Concerns\HordeurenScraperEnvironment;
use App\Mail\HordeurenAnalysisFailed;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Kick off the plissé-hordeuren competitor analysis (the Playwright suite in
 * `competitor-analysis/tests/`) for the address entered in the admin
 * (Tools → Hordeuren concurrentie-analyse).
 *
 * This job only prepares the toolchain (npm install + Chromium on first run)
 * and then dispatches one ScrapeHordeurenCompetitorJob per competitor spec as
 * a batch; MailHordeurenAnalysisReportJob mails the Excel when the batch
 * finishes. Splitting the former multi-hour monolith means a killed worker
 * (deploy restart, OOM) loses one competitor's scrape instead of the whole
 * run, and each competitor retries independently.
 */
class RunHordeurenAnalysisJob implements ShouldQueue
{
    use Dispatchable, HordeurenScraperEnvironment, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Cache key flagging a queued/running analysis for the admin notice,
     * holding the ISO start time. Cleared when the report (or failure) mail
     * goes out; the TTL is a backstop against a run that dies entirely.
     */
    public const RUNNING_CACHE_KEY = 'hordeuren_analysis_running_since';

    /**
     * With failOnTimeout and maxExceptions = 1, the second attempt is reached
     * exclusively when the first attempt died silently (worker killed on
     * deploy/restart, OOM): the installs are idempotent, so re-preparing is
     * safe. A genuine timeout or exception still fails immediately and mails
     * the failure notice.
     *
     * @var int
     */
    public $tries = 2;

    /**
     * Room for npm install (900) plus the Chromium download (1800).
     *
     * @var int
     */
    public $timeout = 3000;

    /**
     * @var bool
     */
    public $failOnTimeout = true;

    /**
     * @var int
     */
    public $maxExceptions = 1;

    /**
     * Run on a dedicated connection/queue (Horizon supervisor-hordeuren) whose
     * retry_after exceeds every hordeuren job's timeout. On the shared queue
     * the short retry_after re-reserved still-running jobs, which surfaced as
     * "has been attempted too many times".
     */
    public function __construct(public string $email)
    {
        $this->onConnection('redis-hordeuren');
        $this->onQueue('hordeuren');
    }

    public function handle(): void
    {
        $dir = (string) config('competitor_pricing.scraper_dir');

        if (! is_dir($dir)) {
            throw new RuntimeException("Scraper directory not found: {$dir}");
        }

        $specs = $this->competitorSpecs($dir);

        if ($specs === []) {
            throw new RuntimeException("No competitor specs found in {$dir}/tests");
        }

        $this->ensurePlaywrightInstalled($dir);
        $this->installChromium($dir);

        /**
         * Fresh start: the suite's recorder is sticky (results-parts/ survives
         * runs so retries only fill missing cells), so the previous analysis'
         * parts must be cleared before the new batch begins.
         */
        File::deleteDirectory($dir.'/results-parts');

        $email = $this->email;
        $startedAt = now();

        Bus::batch(array_map(
            fn (string $spec) => new ScrapeHordeurenCompetitorJob($spec),
            $specs,
        ))
            ->allowFailures()
            ->name('hordeuren-analyse')
            ->finally(function (Batch $batch) use ($email, $startedAt): void {
                MailHordeurenAnalysisReportJob::dispatch($email, $startedAt, $batch->failedJobs);
            })
            ->onConnection('redis-hordeuren')
            ->onQueue('hordeuren')
            ->dispatch();
    }

    public function failed(?Throwable $exception): void
    {
        Cache::forget(self::RUNNING_CACHE_KEY);

        Mail::to($this->email)->send(new HordeurenAnalysisFailed(
            error: $exception?->getMessage() ?? 'Onbekende fout',
        ));
    }

    /**
     * One spec file per competitor shop.
     *
     * @return list<string>
     */
    private function competitorSpecs(string $dir): array
    {
        return array_values(array_map(
            fn (string $path) => basename($path),
            glob($dir.'/tests/*.spec.js') ?: [],
        ));
    }

    /**
     * The Playwright devDependency is skipped by the daily rug pipeline
     * (`npm install --omit=dev`), so install the full dependency set when the
     * test runner is missing.
     */
    private function ensurePlaywrightInstalled(string $dir): void
    {
        if (is_dir($dir.'/node_modules/@playwright/test')) {
            return;
        }

        Process::path($dir)
            ->timeout(900)
            ->env($this->processEnv())
            ->run([$this->nodeBin().'/npm', 'install'])
            ->throw();
    }

    /**
     * Install the headless Chromium binary into the configured browsers path.
     * Always runs: it is a cheap no-op when the matching browser revision is
     * already present, and it repairs the drift a presence check would miss —
     * a Playwright upgrade needing a newer browser revision.
     *
     * The browser download needs no root, but its apt system libraries do.
     * The queue worker runs as an unprivileged user with no interactive sudo,
     * so `--with-deps` is opt-in (config `hordeuren.install_deps`) for the rare
     * host that has passwordless sudo. Otherwise the libraries are installed
     * once, out of band, with `sudo npx playwright install-deps chromium`.
     */
    private function installChromium(string $dir): void
    {
        $command = [$this->nodeBin().'/npx', 'playwright', 'install'];

        if (config('competitor_pricing.hordeuren.install_deps') === true) {
            $command[] = '--with-deps';
        }

        $command[] = 'chromium';

        Process::path($dir)
            ->timeout(1800)
            ->env($this->processEnv())
            ->run($command)
            ->throw();
    }
}
