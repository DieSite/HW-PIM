<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobTimedOut;
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
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            JobProcessing::class             => 'onStarted',
            JobProcessed::class              => 'onFinished',
            JobTimedOut::class               => 'onTimedOut',
            JobReleasedAfterException::class => 'onReleased',
            JobFailed::class                 => 'onFailed',
        ];
    }

    public function onStarted(JobProcessing $event): void
    {
        self::$startedAt[(string) $event->job->uuid()] = microtime(true);

        $context = $this->context($event->connectionName, $event->job);

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
        unset(self::$startedAt[(string) $event->job->uuid()]);
    }

    public function onTimedOut(JobTimedOut $event): void
    {
        Log::channel('queue')->warning('job timed out', $this->context($event->connectionName, $event->job));
        unset(self::$startedAt[(string) $event->job->uuid()]);
    }

    public function onReleased(JobReleasedAfterException $event): void
    {
        Log::channel('queue')->warning('job released after exception', $this->context($event->connectionName, $event->job));
        unset(self::$startedAt[(string) $event->job->uuid()]);
    }

    public function onFailed(JobFailed $event): void
    {
        $context = $this->context($event->connectionName, $event->job) + [
            'exception' => $event->exception->getMessage(),
        ];

        if ($event->exception instanceof MaxAttemptsExceededException
            && ! $event->exception instanceof TimeoutExceededException) {
            $context['likely_cause'] = $this->likelyCause($event->connectionName, $context['seconds_since_dispatch']);
        }

        Log::channel('queue')->error('job failed', $context);
        unset(self::$startedAt[(string) $event->job->uuid()]);
    }

    /**
     * A MaxAttemptsExceededException is thrown when a job is popped with its
     * attempts already spent — meaning an earlier attempt never reported back.
     * When the job's age since dispatch matches the connection's retry_after,
     * that earlier attempt's worker died without failing the job.
     */
    private function likelyCause(string $connection, ?float $secondsSinceDispatch): string
    {
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

        if ($secondsSinceDispatch !== null && $retryAfter > 0 && abs($secondsSinceDispatch - $retryAfter) < 90) {
            return "previous attempt died silently (worker killed on deploy/restart, or OOM); reservation expired after retry_after={$retryAfter}s";
        }

        return 'attempts exhausted without a recorded exception on the final attempt';
    }

    /**
     * @return array{job: string, uuid: ?string, connection: string, queue: ?string, attempt: int, seconds_since_dispatch: ?float, runtime: ?float}
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
        ];
    }
}
