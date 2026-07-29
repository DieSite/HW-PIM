<?php

namespace App\Jobs;

use App\Jobs\Concerns\HordeurenScraperEnvironment;
use DateTimeInterface;
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
 * Playwright spec. A failing scrape is not retried ({@see $maxExceptions}); a
 * scrape whose worker was killed is resumed, and because the suite's recorder
 * is sticky (results-parts/ keeps every scraped price) that resume only
 * re-attempts the cells still missing. The suite's global teardown rebuilds
 * the Excel after every spec run, so the report is complete once the batch is.
 */
class ScrapeHordeurenCompetitorJob implements ShouldQueue
{
    use Batchable, Dispatchable, HordeurenScraperEnvironment, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A spec may be handed to a worker this many times before we stop resuming
     * it. The ceiling has to be enforced here, in handle(), because $tries
     * cannot do it: while retryUntil() is in the future,
     * Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts returns before it ever
     * looks at the attempt count, so $tries is inert on this queue whatever it
     * is set to. Attempt 24 of a spec (Sentry HUIS-EN-WONEN-PIM-D, 24.7 hours
     * after dispatch) is what that looks like: one silent death per
     * retry_after, all the way to the deadline.
     *
     * Three is chosen against the failure it is meant to survive rather than
     * against the failure it is meant to stop: a scrape that loses its worker
     * to a deploy restart gets a second and a third go, while a spec that kills
     * its worker every time gives up after ~3 hours instead of blocking the
     * report for a day.
     *
     * @var int
     */
    public const MAX_ATTEMPTS = 2;

    /**
     * Retries are bounded by a deadline, not by an attempt count — see
     * retryUntil() and {@see MAX_ATTEMPTS}. An attempt counter cannot tell
     * "this scrape failed" from "the worker carrying this scrape was killed",
     * and every silent death (deploy restart, OOM kill, container replacement)
     * burns an attempt without ever running failed() or recording an exception.
     * With $tries the lost attempts surfaced as MaxAttemptsExceededException on
     * a job that had never even started: attempt $tries + 1, ~0.01s runtime.
     *
     * @var int
     */
    public $tries = 0;

    /**
     * One attempt at actually scraping: the first time handle() throws, the
     * job fails and the competitor is left out of this run. Re-running a spec
     * that just failed rarely turns a failure into a price — a changed
     * configurator or a blocked request fails again — it only costs the batch
     * another {@see $timeout} before the report can go out.
     *
     * This counter advances only in the worker's catch path, so it bounds real
     * failures without touching the free retries a killed worker needs. It
     * needs a shared cache store (production runs the file driver), which the
     * worker gets from WorkCommand::runWorker().
     *
     * @var int
     */
    public $maxExceptions = 1;

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

    /**
     * While this deadline is in the future the worker skips the attempt-count
     * check entirely (Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts), so
     * no number of silent worker deaths can produce a
     * MaxAttemptsExceededException.
     *
     * The deadline is frozen into the payload at dispatch time and every
     * competitor of a run is dispatched at once, so it must cover a whole
     * batch, not one scrape: the specs run sequentially on the single
     * supervisor-hordeuren process, so a worst-case pass is
     * (competitors × $timeout) ≈ 6 hours. A day leaves room for retries on top
     * of that while still guaranteeing an abandoned run cannot linger forever.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        /**
         * Every arrival here past the ceiling is a resumed attempt: a scrape
         * that fails outright is stopped by $maxExceptions long before this,
         * and one that succeeds never comes back. So the spec is taking its
         * worker down with it, and re-running it only costs the batch another
         * retry_after before the report can go out.
         */
        if ($this->attempts() > self::MAX_ATTEMPTS) {
            $this->fail(new RuntimeException(sprintf(
                'Scrape %s opgegeven na %d pogingen: de worker sneuvelde elke keer voordat de scrape af was.',
                $this->spec,
                self::MAX_ATTEMPTS,
            )));

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
