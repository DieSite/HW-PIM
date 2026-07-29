<?php

use App\Jobs\ScrapeHordeurenCompetitorJob;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\Fixtures\Queue\AttemptCappedProbeJob;
use Tests\Fixtures\Queue\DeadlineBoundedProbeJob;

/**
 * These tests drive Laravel's real Worker against a real Redis queue to
 * reproduce — and then disprove — the production incident: a
 * ScrapeHordeurenCompetitorJob that fails with "has been attempted too many
 * times" at attempt $tries + 1, after a runtime of ~0.01s, without its
 * handle() ever having run.
 *
 * The cause is not the timing invariant (retry_after 3600 already exceeds the
 * job timeout 1200); it is that attempt counting cannot distinguish "this
 * scrape failed" from "the worker carrying this scrape was killed". A deploy
 * restart, an OOM kill or a container replacement reserves the job, bumps its
 * attempt counter and then vanishes without running failed(), releasing the
 * job or recording an exception. Once retry_after elapses the job returns to
 * the queue with a burnt attempt, and the attempt after the last one dies
 * instantly on the max-attempts check in
 * Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts().
 */
const WORKER_DEATH_CONNECTION = 'worker-death-test';

/**
 * A connection whose reservations expire the moment they are made, so an
 * abandoned job returns to the queue on the very next pop. That compresses
 * "worker died, then retry_after elapsed" into something a test can drive,
 * without changing anything about how the job itself is treated.
 */
function bootWorkerDeathQueue(): string
{
    $queue = 'worker-death-test-'.uniqid();

    config()->set('queue.connections.'.WORKER_DEATH_CONNECTION, [
        'driver'      => 'redis',
        'connection'  => 'default',
        'queue'       => $queue,
        'retry_after' => 0,
        'block_for'   => null,
    ]);

    $GLOBALS['worker_death_queues'][] = $queue;

    return $queue;
}

/**
 * Simulate SIGKILL: reserve the job (which increments its attempt counter,
 * exactly as a real worker does) and then abandon it. No delete(), no
 * release(), no failed() — the process simply stops existing.
 */
function killWorkerMidJob(string $queue): void
{
    $reserved = Queue::connection(WORKER_DEATH_CONNECTION)->pop($queue);

    expect($reserved)->not->toBeNull('Expected a job on the queue to kill the worker on');
}

/**
 * Run one job through the real worker and describe every failure it produced.
 *
 * Only the class and message are kept: a Throwable drags the worker and the
 * whole container along in its trace, and PHPUnit exports that entire object
 * graph when an assertion about it fails.
 *
 * @return array<int, array{class: string, message: string}>
 */
function runOneJobCapturingFailures(string $queue): array
{
    $failures = [];

    Event::listen(JobFailed::class, function (JobFailed $event) use (&$failures): void {
        $failures[] = [
            'class'   => $event->exception::class,
            'message' => $event->exception->getMessage(),
        ];
    });

    /**
     * setCache() mirrors how the worker is wired in production: Horizon's
     * horizon:work inherits runWorker() from Illuminate's WorkCommand, which
     * hands the worker the cache store that backs the maxExceptions counter.
     */
    app('queue.worker')
        ->setCache(app('cache')->driver())
        ->runNextJob(
            WORKER_DEATH_CONNECTION,
            $queue,
            new WorkerOptions(sleep: 0, maxTries: 0)
        );

    return $failures;
}

function scraperDirForWorkerDeathTest(): string
{
    $dir = sys_get_temp_dir().'/worker-death-scraper-'.uniqid();

    File::makeDirectory($dir.'/tests', 0755, true);
    file_put_contents($dir.'/tests/01-a.spec.js', '// spec');

    config()->set('competitor_pricing.scraper_dir', $dir);
    config()->set('competitor_pricing.hordeuren.browsers_path', $dir.'/browsers');

    $GLOBALS['worker_death_dirs'][] = $dir;

    return $dir;
}

beforeEach(function () {
    AttemptCappedProbeJob::$handled = 0;
    DeadlineBoundedProbeJob::$handled = 0;
    DeadlineBoundedProbeJob::$shouldThrow = false;
});

afterEach(function () {
    foreach ($GLOBALS['worker_death_queues'] ?? [] as $queue) {
        Redis::connection()->del(['queues:'.$queue, 'queues:'.$queue.':reserved', 'queues:'.$queue.':delayed']);
    }

    foreach ($GLOBALS['worker_death_dirs'] ?? [] as $dir) {
        File::deleteDirectory($dir);
    }

    $GLOBALS['worker_death_queues'] = [];
    $GLOBALS['worker_death_dirs'] = [];
});

it('reproduces the production failure: attempt-capped jobs die of MaxAttemptsExceeded after silent worker deaths', function () {
    $queue = bootWorkerDeathQueue();

    dispatch((new AttemptCappedProbeJob())->onConnection(WORKER_DEATH_CONNECTION)->onQueue($queue));

    foreach (range(1, 3) as $ignored) {
        killWorkerMidJob($queue);
    }

    $failures = runOneJobCapturingFailures($queue);

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['class'])->toBe(MaxAttemptsExceededException::class)
        ->and($failures[0]['message'])->toContain('has been attempted too many times')
        ->and($failures[0]['message'])->toContain(AttemptCappedProbeJob::class);

    /** Precisely the production symptom: the job never executed even once. */
    expect(AttemptCappedProbeJob::$handled)->toBe(0);
});

it('freezes a retry deadline into the payload the horizon queue dispatches', function () {
    $queue = bootWorkerDeathQueue();

    dispatch(
        (new ScrapeHordeurenCompetitorJob('01-a.spec.js'))
            ->onConnection(WORKER_DEATH_CONNECTION)
            ->onQueue($queue)
    );

    $payload = json_decode((string) Redis::connection()->lindex('queues:'.$queue, 0), true);

    /** Production dispatches through Horizon's queue, so the payload the worker reads is this one. */
    expect(Queue::connection(WORKER_DEATH_CONNECTION))->toBeInstanceOf(Laravel\Horizon\RedisQueue::class)
        ->and($payload['retryUntil'])->toBeGreaterThan(now()->getTimestamp())
        ->and($payload['maxTries'])->toBe(0)
        ->and($payload['maxExceptions'])->toBe(1);
});

it('never lets a deadline-bounded job be failed by silent worker deaths', function () {
    $queue = bootWorkerDeathQueue();

    dispatch((new DeadlineBoundedProbeJob())->onConnection(WORKER_DEATH_CONNECTION)->onQueue($queue));

    foreach (range(1, 12) as $ignored) {
        killWorkerMidJob($queue);
    }

    $failures = runOneJobCapturingFailures($queue);

    expect($failures)->toBe([])
        ->and(DeadlineBoundedProbeJob::$handled)->toBe(1);
});

it('still fails a deadline-bounded job once it genuinely throws maxExceptions times', function () {
    $queue = bootWorkerDeathQueue();

    DeadlineBoundedProbeJob::$shouldThrow = true;

    dispatch((new DeadlineBoundedProbeJob())->onConnection(WORKER_DEATH_CONNECTION)->onQueue($queue));

    $failures = [];

    foreach (range(1, 3) as $ignored) {
        $failures = [...$failures, ...runOneJobCapturingFailures($queue)];
        $this->travel(120)->seconds();
    }

    expect(DeadlineBoundedProbeJob::$handled)->toBe(3)
        ->and($failures)->toHaveCount(1)
        ->and($failures[0]['message'])->toBe('probe failure');
});

/**
 * Queue one scrape and take $deaths workers down with it, leaving the job on
 * the queue with that many attempts burnt.
 */
function scrapeSurvivingWorkerDeaths(int $deaths): string
{
    $queue = bootWorkerDeathQueue();
    scraperDirForWorkerDeathTest();

    dispatch(
        (new ScrapeHordeurenCompetitorJob('01-a.spec.js'))
            ->onConnection(WORKER_DEATH_CONNECTION)
            ->onQueue($queue)
    );

    foreach (range(1, $deaths) as $ignored) {
        killWorkerMidJob($queue);
    }

    return $queue;
}

it('runs the competitor scrape after a silent worker death instead of failing it', function () {
    Process::fake();

    /** The last attempt the ceiling still allows: deaths are free up to here. */
    $queue = scrapeSurvivingWorkerDeaths(ScrapeHordeurenCompetitorJob::MAX_ATTEMPTS - 1);

    expect(runOneJobCapturingFailures($queue))->toBe([]);

    Process::assertRan(fn ($process) => str_contains(
        implode(' ', (array) $process->command),
        'playwright test tests/01-a.spec.js'
    ));
});

it('gives up on a competitor scrape that has taken the ceiling in workers down with it', function () {
    Process::fake();

    $queue = scrapeSurvivingWorkerDeaths(ScrapeHordeurenCompetitorJob::MAX_ATTEMPTS);

    $failures = runOneJobCapturingFailures($queue);

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['class'])->toBe(RuntimeException::class)
        ->and($failures[0]['message'])->toContain('01-a.spec.js')
        ->and($failures[0]['message'])->toContain('opgegeven na 3 pogingen');

    /**
     * The batch gets its failed job now rather than a 24th pass over the
     * configurator, so the report mail is not held for another retry_after.
     */
    Process::assertNothingRan();
});

it('caps genuinely failing competitor scrapes so a broken spec cannot retry forever', function () {
    Process::fake([
        '*playwright*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
    ]);

    $queue = bootWorkerDeathQueue();
    scraperDirForWorkerDeathTest();

    $job = new ScrapeHordeurenCompetitorJob('01-a.spec.js');

    dispatch($job->onConnection(WORKER_DEATH_CONNECTION)->onQueue($queue));

    $failures = [];

    for ($attempt = 1; $attempt <= $job->maxExceptions + 2; $attempt++) {
        $failures = [...$failures, ...runOneJobCapturingFailures($queue)];

        /** Step well past any release delay so a retried job is available again. */
        $this->travel(120)->seconds();
    }

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['class'])->not->toBe(MaxAttemptsExceededException::class)
        ->and($failures[0]['message'])->toContain('01-a.spec.js');
});
