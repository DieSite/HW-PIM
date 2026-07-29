<?php

use App\Jobs\ApplyDeMunkStockJob;
use App\Jobs\BulkEditProductsJob;
use App\Jobs\FetchDeMunkCollectionStockJob;
use App\Jobs\Middleware\DisconnectsIdleRedis;
use Illuminate\Support\Facades\Redis;

/**
 * The failure this middleware exists for: a job that spends minutes without
 * issuing a Redis command still ends with one — RedisJob::delete() retiring it
 * from the reserved set — and Redis has hung up on the idle socket by then.
 * The worker quits on the lost connection, the job stays reserved, and
 * retry_after later it returns as an unexplained extra attempt even though the
 * work succeeded. Confirmed in production on the hordeuren scrape.
 */
it('closes every resolved redis connection before the job body runs', function () {
    $order = [];

    $connections = [];

    foreach (['default', 'horizon'] as $name) {
        $connection = Mockery::mock();
        $connection->shouldReceive('disconnect')->once()->andReturnUsing(function () use (&$order, $name) {
            $order[] = 'disconnect:'.$name;
        });

        $connections[$name] = $connection;
    }

    Redis::shouldReceive('connections')->andReturn($connections);

    $returned = (new DisconnectsIdleRedis())->handle(new stdClass(), function () use (&$order) {
        $order[] = 'job';

        return 'done';
    });

    expect($order)->toBe(['disconnect:default', 'disconnect:horizon', 'job'])
        ->and($returned)->toBe('done');
});

/**
 * Horizon resolves its own Redis connection alongside the queue's. An earlier
 * copy of this disconnect named 'default' and 'cache' explicitly and so left
 * Horizon's socket open — iterating whatever the worker actually resolved is
 * what keeps that from silently regressing.
 */
it('touches only the connections the worker actually resolved', function () {
    Redis::shouldReceive('connections')->andReturn(null);

    expect(fn () => (new DisconnectsIdleRedis())->handle(new stdClass(), fn () => null))
        ->not->toThrow(Throwable::class);
});

/**
 * Every job whose body can run for minutes without reaching the queue has to
 * carry this, or it fails for a reason its own failure record cannot explain.
 * Two jobs already hit this in production and were patched one at a time.
 */
it('applies the middleware to every long job that goes quiet on redis', function () {
    $jobs = [
        FetchDeMunkCollectionStockJob::class => new FetchDeMunkCollectionStockJob('Aruba'),
        ApplyDeMunkStockJob::class           => new ApplyDeMunkStockJob(['Aruba']),
        BulkEditProductsJob::class           => new BulkEditProductsJob([], [], false, 1),
    ];

    foreach ($jobs as $class => $job) {
        $middleware = array_map(fn ($m) => $m::class, $job->middleware());

        expect(in_array(DisconnectsIdleRedis::class, $middleware, true))->toBeTrue(
            "[{$class}] runs for minutes without issuing a Redis command, so its sockets go stale before the worker retires it. It must apply DisconnectsIdleRedis."
        );
    }
});
