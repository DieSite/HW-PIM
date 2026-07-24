<?php

use App\Jobs\ApplyDeMunkStockJob;
use App\Jobs\FetchDeMunkCollectionStockJob;
use App\Jobs\ImportVoorraadDeMunkJob;

it('runs every demunk job on the dedicated connection and queue', function () {
    $jobs = [
        new ImportVoorraadDeMunkJob(),
        new FetchDeMunkCollectionStockJob('BASIC'),
        new ApplyDeMunkStockJob(['BASIC']),
    ];

    foreach ($jobs as $job) {
        expect($job->connection)->toBe('redis-demunk');
        expect($job->queue)->toBe('demunk');
    }
});

it('reaches the orchestrator retry only through silent worker death', function () {
    $job = new ImportVoorraadDeMunkJob();

    expect($job->tries)->toBe(2);
    expect($job->maxExceptions)->toBe(1);
    expect($job->failOnTimeout)->toBeTrue();
});

it('retries a flaky collection fetch', function () {
    $job = new FetchDeMunkCollectionStockJob('BASIC');

    expect($job->tries)->toBeGreaterThan(1);
    expect($job->failOnTimeout)->toBeTrue();
});
