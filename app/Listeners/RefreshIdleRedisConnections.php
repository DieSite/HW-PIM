<?php

namespace App\Listeners;

use App\Jobs\Middleware\DisconnectsIdleRedis;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobTimedOut;

/**
 * Drops a worker's Redis sockets before it pops a job after a quiet spell.
 *
 * Redis closes a connection after 120 idle seconds (production `timeout`). A
 * worker polls its queue every few seconds, which keeps the queue connection
 * alive, but Horizon's own "horizon" connection is only touched around a job
 * and sits idle for as long as the queue is empty. The pop that ends the quiet
 * spell reserves the job and then Horizon's JobReserved listener writes over the
 * dead socket: Predis throws "Error while reading line from the server",
 * Worker::getNextJob() swallows it and quits on the lost connection, and the
 * reserved job never reaches JobProcessing. It resurfaces retry_after later as
 * a MaxAttemptsExceededException with nothing else to explain it.
 *
 * {@see DisconnectsIdleRedis} covers the idle stretch inside a long job; this
 * covers the one between jobs. Predis reconnects lazily, so a disconnect costs
 * one reconnect on the next command.
 */
class RefreshIdleRedisConnections
{
    /**
     * Comfortably below the production Redis idle timeout of 120 seconds.
     */
    public const MAX_IDLE_SECONDS = 60;

    /**
     * When this worker last finished with a job or refreshed its sockets.
     * Static because the subscriber is resolved fresh per event; the worker
     * process is exactly its scope.
     */
    private static ?int $idleSince = null;

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            JobPopping::class                => 'onPopping',
            JobProcessed::class              => 'onJobDone',
            JobFailed::class                 => 'onJobDone',
            JobReleasedAfterException::class => 'onJobDone',
            JobTimedOut::class               => 'onJobDone',
        ];
    }

    public function onPopping(JobPopping $event): void
    {
        $now = now()->getTimestamp();

        if (self::$idleSince === null) {
            self::$idleSince = $now;

            return;
        }

        if ($now - self::$idleSince < self::MAX_IDLE_SECONDS) {
            return;
        }

        DisconnectsIdleRedis::now();

        self::$idleSince = $now;
    }

    public function onJobDone(): void
    {
        self::$idleSince = now()->getTimestamp();
    }
}
