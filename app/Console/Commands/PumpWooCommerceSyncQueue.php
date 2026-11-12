<?php

namespace App\Console\Commands;

use App\Services\WooCommerce\WooCommerceSyncQueue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Safety net for the WooCommerce sync waiting room: every finished sync
 * already pulls the next one, but a sync whose job was lost never finishes.
 * This requeues those and fills any free slot.
 */
#[Signature('wc:pump-sync-queue')]
#[Description('Put waiting WooCommerce syncs on the queue and requeue syncs whose job was lost')]
class PumpWooCommerceSyncQueue extends Command
{
    public function handle(WooCommerceSyncQueue $syncQueue): int
    {
        $syncQueue->pump();

        $this->info("{$syncQueue->waitingCount()} product(s) waiting for a WooCommerce sync.");

        return self::SUCCESS;
    }
}
