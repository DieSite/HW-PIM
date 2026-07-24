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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Close out a hordeuren analysis batch: verify the Excel report was rebuilt,
 * summarize results.json and mail the report. Dispatched by the batch's
 * finally callback, so it runs whether or not individual competitor scrapes
 * failed — a partly filled report still goes out, flagged as such.
 */
class MailHordeurenAnalysisReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The door configurations every competitor is checked against: six
     * generic sizes plus the own assortment (7 type codes, each as single
     * and double door, in black and grey mesh).
     * Mirrors `competitor-analysis/tests/sizes.js`.
     *
     * @var list<string>
     */
    private const SIZES = [
        'Enkele klein',
        'Enkele middel',
        'Enkele groot',
        'Dubbele klein',
        'Dubbele middel',
        'Dubbele groot',
        '96E zwart gaas',
        '96E grijs gaas',
        '96O zwart gaas',
        '96O grijs gaas',
        '110O zwart gaas',
        '110O grijs gaas',
        '130E zwart gaas',
        '130E grijs gaas',
        '130N zwart gaas',
        '130N grijs gaas',
        '160N zwart gaas',
        '160N grijs gaas',
        '190N zwart gaas',
        '190N grijs gaas',
        'Dubbel 96E zwart gaas',
        'Dubbel 96E grijs gaas',
        'Dubbel 96O zwart gaas',
        'Dubbel 96O grijs gaas',
        'Dubbel 110O zwart gaas',
        'Dubbel 110O grijs gaas',
        'Dubbel 130E zwart gaas',
        'Dubbel 130E grijs gaas',
        'Dubbel 130N zwart gaas',
        'Dubbel 130N grijs gaas',
        'Dubbel 160N zwart gaas',
        'Dubbel 160N grijs gaas',
        'Dubbel 190N zwart gaas',
        'Dubbel 190N grijs gaas',
    ];

    /**
     * @var int
     */
    public $tries = 2;

    /**
     * @var int
     */
    public $backoff = 30;

    /**
     * @var int
     */
    public $timeout = 300;

    /**
     * @var bool
     */
    public $failOnTimeout = true;

    public function __construct(
        public string $email,
        public Carbon $startedAt,
        public int $failedScrapes = 0,
    ) {
        $this->onConnection('redis-hordeuren');
        $this->onQueue('hordeuren');
    }

    public function handle(): void
    {
        $output = (string) config('competitor_pricing.hordeuren.output');

        if (! $this->reportWasRebuilt($output)) {
            throw new RuntimeException(
                'De Playwright-batch heeft geen nieuw Excel-rapport opgeleverd; '
                ."waarschijnlijk zijn alle {$this->failedScrapes} scrapes gecrasht voordat er iets is opgehaald."
            );
        }

        $summary = $this->summarizeResults();

        Mail::to($this->email)->send(new HordeurenAnalysisReport(
            reportPath: $output,
            summary: $summary,
            hadFailures: $this->failedScrapes > 0 || ($summary['missing'] ?? 0) > 0,
            startedAt: $this->startedAt,
            finishedAt: now(),
        ));

        Cache::forget(RunHordeurenAnalysisJob::RUNNING_CACHE_KEY);
    }

    public function failed(?Throwable $exception): void
    {
        Cache::forget(RunHordeurenAnalysisJob::RUNNING_CACHE_KEY);

        Mail::to($this->email)->send(new HordeurenAnalysisFailed(
            error: $exception?->getMessage() ?? 'Onbekende fout',
        ));
    }

    /**
     * Every spec run exits through the suite's global teardown, which rebuilds
     * the Excel — so a report older than the analysis start means no scrape
     * got far enough to record anything.
     */
    private function reportWasRebuilt(string $output): bool
    {
        clearstatcache(true, $output);

        return is_file($output) && filemtime($output) >= $this->startedAt->getTimestamp();
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
