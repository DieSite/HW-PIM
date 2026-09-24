<?php

use App\Jobs\GenerateProductDescriptionJob;
use App\Jobs\ScrapeHordeurenCompetitorJob;
use App\Listeners\QueueLifecycleLogger;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobPopped;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Log;

/**
 * "…has been attempted too many times" now has two very different meanings, and
 * the message alone cannot tell them apart. The log line must, or the next
 * incident is as ambiguous as the last one.
 */
function lifecycleJobDouble(?int $retryUntil, float $secondsSinceDispatch): RedisJob
{
    $job = Mockery::mock(RedisJob::class);

    $job->shouldReceive('payload')->andReturn(['pushedAt' => microtime(true) - $secondsSinceDispatch]);
    $job->shouldReceive('uuid')->andReturn('uuid-1');
    $job->shouldReceive('resolveName')->andReturn(ScrapeHordeurenCompetitorJob::class);
    $job->shouldReceive('getQueue')->andReturn('hordeuren');
    $job->shouldReceive('attempts')->andReturn(7);
    $job->shouldReceive('retryUntil')->andReturn($retryUntil);

    return $job;
}

/**
 * @return array<string, mixed>
 */
function captureQueueFailureLog(RedisJob $job): array
{
    $captured = [];

    Log::shouldReceive('channel')->with('queue')->andReturn($channel = Mockery::mock());

    $channel->shouldReceive('error')->once()->andReturnUsing(
        function (string $message, array $context) use (&$captured): void {
            $captured = $context;
        }
    );

    (new QueueLifecycleLogger())->onFailed(new JobFailed(
        'redis-hordeuren',
        $job,
        MaxAttemptsExceededException::forJob($job),
    ));

    return $captured;
}

it('labels a deadline expiry as something other than the attempt-burning failure', function () {
    $context = captureQueueFailureLog(
        lifecycleJobDouble(retryUntil: now()->subHour()->getTimestamp(), secondsSinceDispatch: 90000)
    );

    expect($context['likely_cause'])->toContain('retryUntil deadline')
        ->and($context['likely_cause'])->toContain('NOT the attempt-burning failure')
        ->and($context['likely_cause'])->not->toContain('reservation expired after retry_after');
});

it('still points at a silently killed worker for jobs that bound retries by attempts', function () {
    $retryAfter = (int) config('queue.connections.redis-hordeuren.retry_after');

    $context = captureQueueFailureLog(
        lifecycleJobDouble(retryUntil: null, secondsSinceDispatch: $retryAfter)
    );

    expect($context['likely_cause'])->toContain('died silently')
        ->and($context['likely_cause'])->toContain("retry_after={$retryAfter}s");
});

/**
 * The attempt that dies is the one worth seeing; its MaxAttemptsExceededException
 * only arrives retry_after seconds later, from another process.
 */
function lifecycleProcessingJobDouble(string $uuid): RedisJob
{
    $job = Mockery::mock(RedisJob::class);

    $job->shouldReceive('payload')->andReturn(['pushedAt' => microtime(true) - 5]);
    $job->shouldReceive('uuid')->andReturn($uuid);
    $job->shouldReceive('resolveName')->andReturn(GenerateProductDescriptionJob::class);
    $job->shouldReceive('getQueue')->andReturn('ai');
    $job->shouldReceive('attempts')->andReturn(1);

    return $job;
}

it('reports an attempt that is still running when the worker process exits', function () {
    $logger = new QueueLifecycleLogger();
    $logged = [];

    Log::shouldReceive('channel')->with('queue')->andReturn($channel = Mockery::mock());
    $channel->shouldReceive('info', 'warning');
    $channel->shouldReceive('critical')->andReturnUsing(function (string $message, array $context) use (&$logged): void {
        $logged[] = [$message, $context];
    });

    \Sentry::shouldReceive('captureMessage')->once()->with(Mockery::pattern('/GenerateProductDescriptionJob/'), Mockery::any());

    $logger->onStarted(new JobProcessing('redis-ai', lifecycleProcessingJobDouble('uuid-died')));
    $logger->onStarted(new JobProcessing('redis-ai', $finished = lifecycleProcessingJobDouble('uuid-done')));
    $logger->onFinished(new JobProcessed('redis-ai', $finished));

    $logger->onShutdown();

    expect($logged)->toHaveCount(1)
        ->and($logged[0][0])->toBe('worker exited mid-job')
        ->and($logged[0][1]['uuid'])->toBe('uuid-died')
        ->and($logged[0][1]['pid'])->toBe(getmypid())
        ->and($logged[0][1]['release'])->toBe(basename(base_path()))
        ->and($logged[0][1])->toHaveKey('last_error');
});

it('stays quiet at shutdown when every attempt reported back', function () {
    $logger = new QueueLifecycleLogger();

    Log::shouldReceive('channel')->with('queue')->andReturn($channel = Mockery::mock());
    $channel->shouldReceive('info', 'warning');
    $channel->shouldNotReceive('critical');
    \Sentry::shouldReceive('captureMessage')->never();

    $logger->onStarted(new JobProcessing('redis-ai', $job = lifecycleProcessingJobDouble('uuid-ok')));
    $logger->onFinished(new JobProcessed('redis-ai', $job));

    $logger->onShutdown();
});

it('reports a job the worker reserved but never started when the process exits', function () {
    $logger = new QueueLifecycleLogger();
    $logged = [];

    Log::shouldReceive('channel')->with('queue')->andReturn($channel = Mockery::mock());
    $channel->shouldReceive('critical')->andReturnUsing(function (string $message, array $context) use (&$logged): void {
        $logged[] = $context;
    });
    \Sentry::shouldReceive('captureMessage')->once();

    $logger->onPopped(new JobPopped('redis-ai', lifecycleProcessingJobDouble('uuid-popped')));

    $logger->onShutdown();

    expect($logged)->toHaveCount(1)
        ->and($logged[0]['uuid'])->toBe('uuid-popped')
        ->and($logged[0]['phase'])->toBe('popped');
});
