<?php

namespace App\Jobs;

use App\Mail\NewEurogrosEanNumbers;
use App\Models\EurogrosMissingEanNumber;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class NotifyMissingEurogrosEansJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public CarbonImmutable $since) {}

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
