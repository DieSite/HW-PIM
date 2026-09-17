<?php

namespace App\Monitor;

use App\Jobs\ApplyDeMunkStockJob;
use App\Jobs\BulkEditProductsJob;
use App\Jobs\GenerateAiDescriptionsJob;
use App\Jobs\ImportVoorraadDeMunkJob;
use App\Jobs\ImportVoorraadEurogrosJob;
use App\Jobs\MailHordeurenAnalysisReportJob;
use App\Jobs\RunHordeurenAnalysisJob;
use Diesite\Monitor\Monitor;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Shows a permanently failed queue job on the DieSite TV board, at most once
 * per job class per throttle window so a failing bulk run can't flood it.
 */
class AlertFailedJob
{
    public const THROTTLE_SECONDS = 900;

    /**
     * Jobs that already push their own, more specific, negative event.
     *
     * @var array<int, class-string>
     */
    public const SELF_REPORTING = [
        ApplyDeMunkStockJob::class,
        BulkEditProductsJob::class,
        GenerateAiDescriptionsJob::class,
        ImportVoorraadDeMunkJob::class,
        ImportVoorraadEurogrosJob::class,
        MailHordeurenAnalysisReportJob::class,
        RunHordeurenAnalysisJob::class,
    ];

    public function handle(JobFailed $event): void
    {
        try {
            $jobClass = $event->job->resolveName();

            if (in_array($jobClass, self::SELF_REPORTING, true)) {
                return;
            }

            if (! Cache::add("diesite-monitor:job-failed:{$jobClass}", true, self::THROTTLE_SECONDS)) {
                return;
            }

            Monitor::negative(
                'Job mislukt: '.class_basename($jobClass),
                Str::limit($event->exception->getMessage(), 120),
                '⚠️'
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
