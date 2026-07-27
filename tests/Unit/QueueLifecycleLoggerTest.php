<?php

use App\Jobs\ScrapeHordeurenCompetitorJob;
use App\Listeners\QueueLifecycleLogger;
use Illuminate\Queue\Events\JobFailed;
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
