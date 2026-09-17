<?php

namespace App\Monitor;

use Diesite\Monitor\Metrics\QueueStats;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Contracts\MetricsRepository;

/**
 * The package reads pending counts from the `jobs` table, but HW-PIM queues
 * live in Redis. This reads the pending size per queue from the Redis
 * connection its Horizon supervisor serves, so the TV board shows real depth.
 */
class HorizonQueueStats extends QueueStats
{
    /**
     * @return array<int, array{queue: string, pending: int, failed: int, rate_per_minute: int|null}>
     */
    public function collect(): array
    {
        $connections = $this->connectionPerQueue();
        $failed = $this->failedPerQueue();
        $rates = $this->horizonRates();

        $queues = config('diesite-monitor.queues', []) ?: array_unique(['default', ...array_keys($connections)]);

        return collect($queues)
            ->map(fn (string $queue): array => [
                'queue'           => $queue,
                'pending'         => $this->pending($connections[$queue] ?? config('queue.default'), $queue),
                'failed'          => $failed[$queue] ?? 0,
                'rate_per_minute' => $rates[$queue] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * Queue name => queue connection, taken from the Horizon supervisors.
     *
     * @return array<string, string>
     */
    public function connectionPerQueue(): array
    {
        $map = [];

        foreach ((array) config('horizon.defaults', []) as $supervisor) {
            $connection = $supervisor['connection'] ?? config('queue.default');

            foreach ((array) ($supervisor['queue'] ?? []) as $queue) {
                $map[$queue] ??= $connection;
            }
        }

        return $map;
    }

    private function pending(string $connection, string $queue): int
    {
        try {
            return (int) Queue::connection($connection)->size($queue);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array<string, int>
     */
    private function failedPerQueue(): array
    {
        try {
            if (! Schema::hasTable('failed_jobs')) {
                return [];
            }

            return DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDay())
                ->selectRaw('queue, count(*) as aggregate')
                ->groupBy('queue')
                ->pluck('aggregate', 'queue')
                ->map(fn (mixed $count): int => (int) $count)
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, int>
     */
    private function horizonRates(): array
    {
        try {
            $metrics = app(MetricsRepository::class);

            return collect($metrics->measuredQueues())
                ->mapWithKeys(fn (string $queue): array => [
                    $queue => (int) round($metrics->throughputForQueue($queue) / 60),
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
