<?php

namespace Tests\Fixtures\Queue;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Mirror of the retry policy the hordeuren jobs now use — a retryUntil()
 * deadline plus a maxExceptions cap — so the harness can prove both halves:
 * silent worker deaths are free, genuine exceptions are still bounded.
 */
class DeadlineBoundedProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public static int $handled = 0;

    public static bool $shouldThrow = false;

    /**
     * @var int
     */
    public $tries = 0;

    /**
     * @var int
     */
    public $maxExceptions = 3;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(): void
    {
        static::$handled++;

        if (static::$shouldThrow) {
            throw new RuntimeException('probe failure');
        }
    }
}
