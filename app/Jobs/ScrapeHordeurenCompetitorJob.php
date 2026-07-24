<?php

namespace App\Jobs;

use App\Jobs\Concerns\HordeurenScraperEnvironment;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Scrape one competitor shop of the hordeuren analysis by running its single
 * Playwright spec. The suite's recorder is sticky (results-parts/ keeps every
 * scraped price), so a retry after a flaky run or a killed worker only
 * re-attempts the missing cells — retries replace the old whole-suite
 * "gap-filling passes". The suite's global teardown rebuilds the Excel after
 * every spec run, so the report is complete once the batch is.
 */
class ScrapeHordeurenCompetitorJob implements ShouldQueue
{
    use Batchable, Dispatchable, HordeurenScraperEnvironment, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Live competitor sites are flaky; each retry only fills the cells still
     * missing thanks to the sticky recorder.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * @var int
     */
    public $backoff = 30;

    /**
     * One spec drives one competitor's configurator through all 34 door
     * configurations; well within half an hour.
     *
     * @var int
     */
    public $timeout = 1200;

    /**
     * @var bool
     */
    public $failOnTimeout = true;

    public function __construct(public string $spec)
    {
        $this->onConnection('redis-hordeuren');
        $this->onQueue('hordeuren');
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $dir = (string) config('competitor_pricing.scraper_dir');

        $result = Process::path($dir)
            ->timeout($this->timeout - 60)
            ->env($this->processEnv())
            ->run([$this->nodeBin().'/npx', 'playwright', 'test', 'tests/'.$this->spec]);

        if (! $result->successful()) {
            throw new RuntimeException(
                "Scrape {$this->spec} liet lege cellen achter: "
                .mb_substr($result->errorOutput() ?: $result->output(), -1000)
            );
        }
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [self::class, $this->spec];
    }
}
