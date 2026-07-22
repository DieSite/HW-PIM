<?php

use App\Jobs\ImportVoorraadDeMunkJob;

it('runs on the dedicated long-running connection and queue', function () {
    $job = new ImportVoorraadDeMunkJob();

    expect($job->connection)->toBe('redis-demunk');
    expect($job->queue)->toBe('demunk');
    expect($job->tries)->toBe(1);
});

it('gives the dedicated connection a retry_after longer than the job timeout', function () {
    $job = new ImportVoorraadDeMunkJob();

    $retryAfter = (int) config("queue.connections.{$job->connection}.retry_after");

    expect(config("queue.connections.{$job->connection}.queue"))->toBe($job->queue);
    expect($retryAfter)->toBeGreaterThan($job->timeout);
});

it('has a horizon supervisor serving the demunk queue on the dedicated connection', function () {
    $job = new ImportVoorraadDeMunkJob();

    $supervisors = collect(config('horizon.defaults'))
        ->filter(fn (array $supervisor) => in_array($job->queue, $supervisor['queue'], true));

    expect($supervisors)->toHaveCount(1);

    $supervisor = $supervisors->first();

    expect($supervisor['connection'])->toBe($job->connection);
    expect((int) $supervisor['timeout'])->toBeGreaterThanOrEqual($job->timeout);
});
