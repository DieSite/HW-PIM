<?php

namespace App\Services\WooCommerce;

use Laravel\Horizon\Contracts\WorkloadRepository;

/**
 * Horizon's workload, plus a row for the WooCommerce sync waiting room:
 * how many products wait and roughly how long until the last one is synced.
 */
class WooCommerceSyncQueueWorkloadRepository implements WorkloadRepository
{
    public function __construct(
        private WorkloadRepository $workload,
        private WooCommerceSyncQueue $syncQueue,
    ) {}

    /**
     * @return array<int, array{name: string, length: int, wait: int, processes: int, split_queues: null|array<int, array{name: string, wait: int, length: int}>}>
     */
    public function get()
    {
        return [
            ...$this->workload->get(),
            [
                'name'         => WooCommerceSyncQueueJobRepository::QUEUE,
                'length'       => $this->syncQueue->waitingCount(),
                'wait'         => $this->syncQueue->estimatedWaitSeconds(),
                'processes'    => 0,
                'split_queues' => null,
            ],
        ];
    }
}
