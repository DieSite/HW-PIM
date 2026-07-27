<?php

namespace Tests\Fixtures\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A job that bounds its retries the way the hordeuren jobs used to: purely by
 * attempt count. Used to prove the worker-death harness reproduces the
 * production MaxAttemptsExceededException before asserting the real jobs no
 * longer suffer from it.
 */
class AttemptCappedProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public static int $handled = 0;

    /**
     * @var int
     */
    public $tries = 3;

    public function handle(): void
    {
        static::$handled++;
    }
}
