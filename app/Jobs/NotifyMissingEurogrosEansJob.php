<?php

namespace App\Jobs;

use App\Mail\NewEurogrosEanNumbers;
use App\Models\EurogrosMissingEanNumber;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use App\Jobs\Middleware\DisconnectsIdleRedis;

class NotifyMissingEurogrosEansJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public CarbonImmutable $since) {}

    /**
     * May run past the Redis idle timeout without issuing a Redis command of
     * its own, so the delete() that retires it on success would find a closed
     * socket. {@see DisconnectsIdleRedis}
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new DisconnectsIdleRedis()];
    }

    public function handle(): void
    {
        $missing = EurogrosMissingEanNumber::where('created_at', '>=', $this->since)
            ->pluck('ean')
            ->all();

        if (count($missing) === 0) {
            return;
        }

        Mail::send(new NewEurogrosEanNumbers($missing));
    }
}
