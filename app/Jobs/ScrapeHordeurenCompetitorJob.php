<?php

namespace App\Jobs;

use App\Jobs\Concerns\HordeurenScraperEnvironment;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Scrape one competitor shop of the hordeuren analysis by running its single
 * Playwright spec.
 *
 * Fail fast: one attempt, no retries. The first spec that fails stops the whole
 * batch and mails the failure (see RunHordeurenAnalysisJob's catch callback),
 * because a run that silently dropped a competitor produces a price comparison
 * that reads as complete but is not. The suite's global teardown rebuilds the
 * Excel after every spec run, so the report is complete once the batch is.
 */
class ScrapeHordeurenCompetitorJob implements ShouldQueue
{
    use Batchable, Dispatchable, HordeurenScraperEnvironment, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * One attempt. Deliberately NOT bounded by retryUntil(): a deadline makes
     * the worker skip the attempt check entirely
     * (Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts returns while the
     * deadline holds), which is exactly how a spec once reached attempt 24 over
     * 24.7 hours. With $tries = 1 a second reservation — the fingerprint of a
     * worker that was killed mid-scrape — fails the job instead of re-running
     * it, so a lost worker surfaces as an alert rather than as another hour of
     * silence.
     *
     * @var int
     */
    public $tries = 1;

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
        /** An earlier competitor already failed and stopped the run. */
        if ($this->batch()?->cancelled()) {
            return;
        }

        $dir = (string) config('competitor_pricing.scraper_dir');

        /**
         * Playwright's output goes straight to the spec log rather than through
         * a PHP buffer: it survives a worker that never returns from here,
         * which is otherwise the one failure that leaves nothing to debug with.
         */
        $command = sprintf(
            '%s playwright test %s >> %s 2>&1',
            escapeshellarg($this->nodeBin().'/npx'),
            escapeshellarg('tests/'.$this->spec),
            escapeshellarg($this->logPath()),
        );

        $this->startLog();

        /**
         * The scrape is minutes of pure subprocess time. Left open, the
         * worker's Redis sockets go idle long enough for Redis to hang up, and
         * the delete() that retires this job on success then dies on the
         * half-closed socket — taking the worker down with a scrape that had
         * actually succeeded. {@see disconnectRedis()}
         */
        $this->disconnectRedis();

        $result = Process::path($dir)
            ->timeout($this->timeout - 60)
            ->env($this->processEnv())
            ->run($command);

        if (! $result->successful()) {
            throw new RuntimeException(
                "Scrape {$this->spec} liet lege cellen achter: ".($this->logTail() ?: '(geen uitvoer)')
            );
        }
    }

    /**
     * One log per spec. Kept across runs so the run before the failing one is
     * still there to compare against.
     */
    private function logPath(): string
    {
        return storage_path('logs/hordeuren/'.$this->spec.'.log');
    }

    private function startLog(): void
    {
        File::ensureDirectoryExists(dirname($this->logPath()));

        File::append($this->logPath(), sprintf(
            "\n=== %s — poging %d ===\n",
            now()->toDateTimeString(),
            $this->attempts(),
        ));
    }

    /**
     * The tail is what goes into the failure mail and Sentry, so it has to stay
     * small enough to be a message rather than a payload.
     */
    private function logTail(int $characters = 1000): string
    {
        if (! File::exists($this->logPath())) {
            return '';
        }

        return trim(mb_substr(File::get($this->logPath()), -$characters));
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [self::class, $this->spec];
    }
}
