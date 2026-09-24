<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobPopped;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use Sentry\State\Scope;

/**
 * Writes one line per queue-job lifecycle transition to the "queue" channel so
 * a silently killed worker (SIGKILL on deploy/restart, OOM) is diagnosable
 * afterwards: an attempt that logged "started" but never "finished"/"failed"/
 * "timed out" died mid-flight. The MaxAttemptsExceededException that surfaces
 * retry_after seconds later is a symptom of that death, not the cause — when
 * one is seen, the failure line carries a "likely_cause" hint saying so.
 *
 * Every line carries the worker pid and the release directory it runs from, so
 * the attempt that died can be tied to a process. A worker that exits while an
 * attempt is still running (a PHP fatal, exit(), a lost connection) reports it
 * from its shutdown hook, and one running a release other than the deployed
 * "current" says so once — only SIGKILL still leaves no trace of its own.
 */
class QueueLifecycleLogger
{
    /**
     * Start time per job uuid, for runtime calculation. Static because the
     * subscriber is resolved fresh per event; the worker process itself is
     * exactly the scope of a running attempt.
     *
     * @var array<string, float>
     */
    private static array $startedAt = [];

    /**
     * Context of the attempts this worker is running, reported if the process
     * exits before they finish.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $inFlight = [];

    private static bool $shutdownHookRegistered = false;

    private static bool $staleReleaseReported = false;

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            JobPopped::class                 => 'onPopped',
            JobProcessing::class             => 'onStarted',
            JobProcessed::class              => 'onFinished',
            JobTimedOut::class               => 'onTimedOut',
            JobReleasedAfterException::class => 'onReleased',
            JobFailed::class                 => 'onFailed',
            WorkerStopping::class            => 'onWorkerStopping',
        ];
    }

    /**
     * Tracks the job from the moment the worker reserves it, not only once it
     * starts: an attempt that dies in between never logs "job started", and
     * would otherwise leave no line at all.
     */
    public function onPopped(JobPopped $event): void
    {
        if ($event->job === null) {
            return;
        }

        self::$inFlight[(string) $event->job->uuid()] = $this->context($event->connectionName, $event->job) + ['phase' => 'popped'];
        $this->registerShutdownHook();
    }

    public function onStarted(JobProcessing $event): void
    {
        self::$startedAt[(string) $event->job->uuid()] = microtime(true);

        $context = $this->context($event->connectionName, $event->job);

        self::$inFlight[(string) $event->job->uuid()] = $context + ['phase' => 'started'];
        $this->registerShutdownHook();
        $this->reportStaleRelease($context);

        Log::channel('queue')->info('job started', $context);

        \Sentry\configureScope(function (Scope $scope) use ($context): void {
            $scope->setTag('queue.attempt', (string) $context['attempt']);
            $scope->setTag('queue.name', (string) $context['queue']);
            $scope->setContext('queue_job', $context);
        });
    }

    public function onFinished(JobProcessed $event): void
    {
        Log::channel('queue')->info('job finished', $this->context($event->connectionName, $event->job));
        $this->forget((string) $event->job->uuid());
    }

    public function onTimedOut(JobTimedOut $event): void
    {
        Log::channel('queue')->warning('job timed out', $this->context($event->connectionName, $event->job));
        $this->forget((string) $event->job->uuid());
    }

    public function onReleased(JobReleasedAfterException $event): void
    {
        Log::channel('queue')->warning('job released after exception', $this->context($event->connectionName, $event->job));
        $this->forget((string) $event->job->uuid());
    }

    public function onFailed(JobFailed $event): void
    {
        $context = $this->context($event->connectionName, $event->job) + [
            'exception' => $event->exception->getMessage(),
        ];

        if ($event->exception instanceof MaxAttemptsExceededException
            && ! $event->exception instanceof TimeoutExceededException) {
            $context['likely_cause'] = $this->likelyCause(
                $event->connectionName,
                $event->job,
                $context['seconds_since_dispatch'],
            );
        }

        Log::channel('queue')->error('job failed', $context);
        $this->forget((string) $event->job->uuid());
    }

    /**
     * A worker stops on its own for a reason (memory limit, lost connection,
     * SIGTERM from a Horizon scale-down or restart). Logged every time: the
     * absence of this line before a death is itself the clue that the process
     * was killed rather than stopped.
     */
    public function onWorkerStopping(WorkerStopping $event): void
    {
        Log::channel('queue')->warning('worker stopping', [
            'status'   => $event->status,
            'pid'      => getmypid(),
            'release'  => basename(base_path()),
            'inFlight' => array_keys(self::$inFlight),
        ]);
    }

    /**
     * Runs when the worker process ends. Anything still in flight at that
     * point died with the process: without this line the only trace is the
     * MaxAttemptsExceededException that surfaces retry_after seconds later.
     */
    public function onShutdown(): void
    {
        if (self::$inFlight === []) {
            return;
        }

        $error = error_get_last();

        foreach (self::$inFlight as $context) {
            $context['runtime'] = isset($context['uuid'], self::$startedAt[$context['uuid']])
                ? round(microtime(true) - self::$startedAt[$context['uuid']], 1)
                : null;
            $context['last_error'] = $error !== null ? "{$error['message']} in {$error['file']}:{$error['line']}" : null;

            Log::channel('queue')->critical('worker exited mid-job', $context);

            \Sentry::captureMessage(
                "Queue worker exited while running {$context['job']}: ".($context['last_error'] ?? 'no PHP error recorded'),
                \Sentry\Severity::error(),
            );
        }

        self::$inFlight = [];

        \Sentry\SentrySdk::getCurrentHub()->getClient()?->flush();
    }

    private function registerShutdownHook(): void
    {
        if (self::$shutdownHookRegistered) {
            return;
        }

        self::$shutdownHookRegistered = true;

        register_shutdown_function(fn () => $this->onShutdown());
    }

    /**
     * Deploys switch the "current" symlink but do not restart Horizon, so a
     * worker can keep running an older release — including one that has since
     * been pruned from disk, where the first class it still has to load kills
     * it without a word.
     *
     * @param  array<string, mixed>  $context
     */
    private function reportStaleRelease(array $context): void
    {
        if (self::$staleReleaseReported) {
            return;
        }

        $current = realpath(dirname(base_path(), 2).'/current');

        if ($current === false || $current === realpath(base_path())) {
            return;
        }

        self::$staleReleaseReported = true;

        Log::channel('queue')->warning('worker runs a stale release', $context + ['current' => basename($current)]);

        \Sentry::captureMessage(
            sprintf('Queue worker %d runs release %s while current is %s — run php artisan horizon:terminate.', getmypid(), basename(base_path()), basename($current)),
            \Sentry\Severity::warning(),
        );
    }

    private function forget(string $uuid): void
    {
        unset(self::$startedAt[$uuid], self::$inFlight[$uuid]);
    }

    /**
     * A MaxAttemptsExceededException is thrown when a job is popped with its
     * retry budget already spent — meaning an earlier attempt never reported
     * back. Which budget ran out matters, because the two cases call for
     * opposite responses and carry the identical error message.
     */
    private function likelyCause(string $connection, Job $job, ?float $secondsSinceDispatch): string
    {
        $retryUntil = method_exists($job, 'retryUntil') ? $job->retryUntil() : null;

        /**
         * The job bounds retries by a deadline, so its attempt count was never
         * consulted: no number of killed workers can produce this. It simply
         * was still being retried when the deadline passed.
         */
        if ($retryUntil !== null) {
            return sprintf(
                'retryUntil deadline (%s) expired — NOT the attempt-burning failure: this job ignores its attempt count. The run was still retrying %s seconds after dispatch, so either it is genuinely that slow or workers are being killed faster than a run can absorb. Grep this log for "job started" lines with no matching "job finished".',
                date('c', (int) $retryUntil),
                $secondsSinceDispatch !== null ? (string) (int) $secondsSinceDispatch : 'an unknown number of',
            );
        }

        $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

        if ($secondsSinceDispatch !== null && $retryAfter > 0 && abs($secondsSinceDispatch - $retryAfter) < 90) {
            return "previous attempt died silently (worker killed on deploy/restart, or OOM); reservation expired after retry_after={$retryAfter}s";
        }

        return 'attempts exhausted without a recorded exception on the final attempt';
    }

    /**
     * @return array{job: string, uuid: ?string, connection: string, queue: ?string, attempt: int, seconds_since_dispatch: ?float, runtime: ?float, pid: int|false, release: string}
     */
    private function context(string $connectionName, Job $job): array
    {
        $payload = $job->payload();
        $pushedAt = isset($payload['pushedAt']) ? (float) $payload['pushedAt'] : null;
        $start = self::$startedAt[(string) $job->uuid()] ?? null;

        return [
            'job'                    => $job->resolveName(),
            'uuid'                   => $job->uuid(),
            'connection'             => $connectionName,
            'queue'                  => $job->getQueue(),
            'attempt'                => $job->attempts(),
            'seconds_since_dispatch' => $pushedAt !== null ? round(microtime(true) - $pushedAt, 1) : null,
            'runtime'                => $start !== null ? round(microtime(true) - $start, 1) : null,
            'pid'                    => getmypid(),
            'release'                => basename(base_path()),
        ];
    }
}
