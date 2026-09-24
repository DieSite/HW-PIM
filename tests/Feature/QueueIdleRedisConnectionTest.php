<?php

use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\Fixtures\Queue\AttemptCappedProbeJob;

/**
 * Production Redis closes a connection after 120 idle seconds. A worker polls
 * its queue on the queue connection every few seconds, but Horizon's own
 * "horizon" connection is only used around a job: it sits idle for as long as
 * the queue is empty. The next pop reserves the job and then Horizon's
 * JobReserved listener writes over that dead socket — Predis throws "Error
 * while reading line from the server", the worker catches it inside
 * getNextJob(), returns no job and quits on the lost connection. The job stays
 * reserved without ever reaching JobProcessing, and comes back retry_after
 * later as a MaxAttemptsExceededException (2026-09-24, GenerateAiDescriptionsJob
 * and GenerateProductDescriptionJob on the idle "ai" queue).
 */
const IDLE_REDIS_CONNECTION = 'idle-redis-test';

function bootIdleRedisQueue(): string
{
    $queue = 'idle-redis-test-'.uniqid();

    config()->set('queue.connections.'.IDLE_REDIS_CONNECTION, [
        'driver'      => 'redis',
        'connection'  => 'default',
        'queue'       => $queue,
        'retry_after' => 3600,
        'block_for'   => null,
    ]);

    $GLOBALS['idle_redis_queues'][] = $queue;

    return $queue;
}

/**
 * What Redis does to a connection past its idle timeout: close it server-side,
 * leaving the client holding a socket it believes is open.
 */
function dropHorizonConnectionServerSide(): void
{
    $horizon = Redis::connection('horizon');
    $horizon->ping();

    $id = $horizon->command('client', ['id']);

    Redis::connection('default')->command('client', ['kill', 'id', $id]);
}

function runOneIdleRedisJob(string $queue): void
{
    app('queue.worker')
        ->setCache(app('cache')->driver())
        ->runNextJob(IDLE_REDIS_CONNECTION, $queue, new WorkerOptions(sleep: 0, maxTries: 0));
}

function reservedIdleRedisJobs(string $queue): int
{
    return (int) Redis::connection('default')->zcard('queues:'.$queue.':reserved');
}

beforeEach(function () {
    AttemptCappedProbeJob::$handled = 0;
});

afterEach(function () {
    foreach ($GLOBALS['idle_redis_queues'] ?? [] as $queue) {
        Redis::connection('default')->del(['queues:'.$queue, 'queues:'.$queue.':reserved', 'queues:'.$queue.':delayed']);
    }

    $GLOBALS['idle_redis_queues'] = [];
});

it('runs the next job after the horizon connection sat idle past the Redis timeout', function () {
    $queue = bootIdleRedisQueue();

    Queue::connection(IDLE_REDIS_CONNECTION)->push(new AttemptCappedProbeJob(), '', $queue);
    runOneIdleRedisJob($queue);
    expect(AttemptCappedProbeJob::$handled)->toBe(1);

    /**
     * Pushed before the drop: in production the dispatch comes from another
     * process with its own connections, here it shares the worker's.
     */
    Queue::connection(IDLE_REDIS_CONNECTION)->push(new AttemptCappedProbeJob(), '', $queue);

    $this->travel(5)->minutes();
    dropHorizonConnectionServerSide();

    runOneIdleRedisJob($queue);

    expect(AttemptCappedProbeJob::$handled)->toBe(2)
        ->and(reservedIdleRedisJobs($queue))->toBe(0);
});

it('reproduces the production loss: a job popped over a dead horizon connection stays reserved without running', function () {
    $queue = bootIdleRedisQueue();

    Queue::connection(IDLE_REDIS_CONNECTION)->push(new AttemptCappedProbeJob(), '', $queue);
    runOneIdleRedisJob($queue);

    Queue::connection(IDLE_REDIS_CONNECTION)->push(new AttemptCappedProbeJob(), '', $queue);

    /**
     * No time passes, so the worker sees no reason to refresh its sockets:
     * exactly the state of a production worker whose connection Redis closed
     * without it knowing.
     */
    dropHorizonConnectionServerSide();

    runOneIdleRedisJob($queue);

    expect(AttemptCappedProbeJob::$handled)->toBe(1)
        ->and(reservedIdleRedisJobs($queue))->toBe(1);
});
