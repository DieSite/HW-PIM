<?php

namespace App\Jobs;

use App\Mail\HordeurenAnalysisFailed;
use App\Mail\HordeurenAnalysisReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Run the plissé-hordeuren competitor analysis (the Playwright suite in
 * `competitor-analysis/tests/`) and mail the resulting Excel report to the
 * address entered in the admin (Tools → Hordeuren concurrentie-analyse).
 *
 * The suite drives live competitor configurators, so a full run takes
 * 10–20 minutes. Chromium and its system libraries are installed on first
 * run (the containers run as root and already ship Node 20).
 */
class RunHordeurenAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The six standard door sizes every competitor is checked against.
     * Mirrors `competitor-analysis/tests/sizes.js`.
     */
    private const SIZES = [
        'Enkele klein',
        'Enkele middel',
        'Enkele groot',
        'Dubbele klein',
        'Dubbele middel',
        'Dubbele groot',
    ];

    /**
     * A failed scrape must not silently re-run for another 10+ minutes; the
     * failure mail tells the user to simply start it again.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * @var int
     */
    public $timeout = 7200;

    public function __construct(public string $email) {}

    public function handle(): void
    {
        $dir = (string) config('competitor_pricing.scraper_dir');
        $output = (string) config('competitor_pricing.hordeuren.output');

        if (! is_dir($dir)) {
            throw new RuntimeException("Scraper directory not found: {$dir}");
        }

        $this->ensurePlaywrightInstalled($dir);
        $this->installChromium($dir);

        $startedAt = now();
        $maxPasses = max(1, (int) config('competitor_pricing.hordeuren.max_passes'));

        /**
         * Pass 1 starts clean (RESET_RESULTS=1); the suite's recorder is
         * sticky, so every later pass keeps the real prices already scraped
         * and only the missing cells get a fresh attempt. A non-zero exit
         * means at least one spec recorded nothing, i.e. an empty cell.
         */
        for ($pass = 1; $pass <= $maxPasses; $pass++) {
            $result = Process::path($dir)
                ->timeout((int) config('competitor_pricing.hordeuren.timeout'))
                ->env($this->processEnv() + ($pass === 1 ? ['RESET_RESULTS' => '1'] : []))
                ->run(['npx', 'playwright', 'test']);

            if ($result->successful()) {
                break;
            }
        }

        if (! $this->reportWasRebuilt($output, $startedAt)) {
            throw new RuntimeException(
                'De Playwright-run heeft geen nieuw Excel-rapport opgeleverd. Output: '
                .mb_substr($result->errorOutput() ?: $result->output(), -2000)
            );
        }

        Mail::to($this->email)->send(new HordeurenAnalysisReport(
            reportPath: $output,
            summary: $this->summarizeResults(),
            hadFailures: ! $result->successful(),
            startedAt: $startedAt,
            finishedAt: now(),
        ));
    }

    public function failed(?Throwable $exception): void
    {
        Mail::to($this->email)->send(new HordeurenAnalysisFailed(
            error: $exception?->getMessage() ?? 'Onbekende fout',
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
            ->run(['npm', 'install'])
            ->throw();
    }

    /**
     * Install headless Chromium (plus its system libraries) into the
     * configured browsers path. Always runs: it is a cheap no-op when the
     * matching browser revision is already present, and it repairs the two
     * drift cases a presence check would miss — a Playwright upgrade needing
     * a new browser revision, and a rebuilt container that kept the browser
     * download (it lives in the mounted repository) but lost the apt
     * libraries.
     */
    private function installChromium(string $dir): void
    {
        Process::path($dir)
            ->timeout(1800)
            ->env($this->processEnv())
            ->run(['npx', 'playwright', 'install', '--with-deps', 'chromium'])
            ->throw();
    }

    /**
     * @return array<string, string>
     */
    private function processEnv(): array
    {
        return [
            'PLAYWRIGHT_BROWSERS_PATH' => (string) config('competitor_pricing.hordeuren.browsers_path'),
        ];
    }

    /**
     * The suite always exits through its teardown, which rebuilds the Excel —
     * so a report older than the run means the suite crashed before scraping.
     */
    private function reportWasRebuilt(string $output, Carbon $startedAt): bool
    {
        clearstatcache(true, $output);

        return is_file($output) && filemtime($output) >= $startedAt->getTimestamp();
    }

    /**
     * Summarize results.json (competitor → size → price/label) for the mail
     * body: how many competitors were checked, how many cells hold a real
     * scraped price versus an honest label such as "n.v.t." or "Op aanvraag",
     * and how many of the expected size cells are still empty.
     *
     * @return array{shops: int, cells: int, priced: int, missing: int}|null
     */
    private function summarizeResults(): ?array
    {
        $path = (string) config('competitor_pricing.hordeuren.results');

        if (! is_file($path)) {
            return null;
        }

        $results = json_decode((string) file_get_contents($path), true);

        if (! is_array($results)) {
            return null;
        }

        $cells = 0;
        $priced = 0;
        $missing = 0;

        foreach ($results as $sizes) {
            if (! is_array($sizes)) {
                continue;
            }

            $missing += count(array_diff(self::SIZES, array_keys($sizes)));

            foreach ($sizes as $value) {
                $cells++;

                if (is_string($value) && str_contains($value, '€')) {
                    $priced++;
                }
            }
        }

        return [
            'shops'   => count($results),
            'cells'   => $cells,
            'priced'  => $priced,
            'missing' => $missing,
        ];
    }
}
