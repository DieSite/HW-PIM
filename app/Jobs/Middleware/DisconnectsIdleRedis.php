<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Redis;

/**
 * Drop the worker's Redis sockets before a long job body runs.
 *
 * A queue worker always ends a job with a Redis command — RedisJob::delete()
 * retires it from the reserved set — but a job that spends minutes on a
 * subprocess, an HTTP crawl or a large database pass issues none in between.
 * Redis hangs up on a connection that idle and Predis never reconnects, so that
 * closing delete() dies with "Error while writing bytes to the server". The
 * worker treats it as a lost connection and quits, the job stays reserved, and
 * retry_after later it comes back as an extra attempt that nothing in the
 * failure record explains — even though the work itself had succeeded.
 *
 * Closing the sockets up front costs nothing: Predis connects lazily, so the
 * post-job bookkeeping opens fresh ones. Registering this as middleware rather
 * than a call inside handle() means it also covers the window between the job
 * being popped and handle() starting, and it puts the decision where a reader
 * looks for it — next to the job's timeout.
 *
 * Apply it to any job on a queue whose bodies run for minutes. It is a no-op
 * for short jobs, so the cost of applying it too widely is nil and the cost of
 * forgetting it is a run that fails without a reason.
 */
class DisconnectsIdleRedis
{
    public function handle(object $job, Closure $next): mixed
    {
        self::now();

        return $next($job);
    }

    /**
     * The same disconnect, for a job that only goes idle partway through its
     * handle() and therefore cannot express this as middleware.
     */
    public static function now(): void
    {
        /**
         * Only connections this worker actually resolved are touched, which
         * covers Horizon's alongside the queue's own and stays correct if
         * either is ever renamed. RedisManager keeps its resolved-connection
         * map null until first use.
         */
        foreach (Redis::connections() ?? [] as $connection) {
            $connection->disconnect();
        }
    }
}
