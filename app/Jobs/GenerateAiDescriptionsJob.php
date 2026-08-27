<?php

namespace App\Jobs;

use App\Jobs\Middleware\DisconnectsIdleRedis;
use App\Models\AiDescriptionRun;
use App\Services\AI\AiDescriptionService;
use DateTimeInterface;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Fans a run out into one generate job per product.
 *
 * The per-product jobs run on the same "ai" queue with several workers, so a
 * few thousand products move through at the provider's pace rather than one at
 * a time.
 */
class GenerateAiDescriptionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 900;

    public $maxExceptions = 1;

    public function __construct(public readonly int $runId)
    {
        $this->onConnection('redis-ai');
        $this->onQueue('ai');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    /**
     * Walks the whole matching set before dispatching, which can take minutes
     * of pure database work. {@see DisconnectsIdleRedis}
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new DisconnectsIdleRedis()];
    }

    public function handle(AiDescriptionService $descriptions): void
    {
        $run = AiDescriptionRun::find($this->runId);

        if (! $run) {
            return;
        }

        $run->update(['status' => 'processing']);

        /** @var list<string> $fields */
        $fields = $run->fields ?? [];

        $jobs = [];

        $descriptions->matchingQuery($run->filters ?? [])
            ->select(['id'])
            ->chunkById(500, function ($products) use (&$jobs, $fields) {
                foreach ($products as $product) {
                    $jobs[] = new GenerateProductDescriptionJob($product->id, $fields, $this->runId);
                }
            });

        if ($jobs === []) {
            $run->update([
                'status'      => 'completed',
                'finished_at' => now(),
            ]);

            return;
        }

        $run->update(['matched_count' => count($jobs)]);

        $runId = $this->runId;

        Bus::batch($jobs)
            ->name("AI-teksten run #{$runId}")
            ->allowFailures()
            ->onConnection('redis-ai')
            ->onQueue('ai')
            ->then(fn (Batch $batch) => AiDescriptionRun::where('id', $runId)->update([
                'status'      => 'completed',
                'finished_at' => now(),
            ]))
            ->catch(fn (Batch $batch, Throwable $exception) => AiDescriptionRun::where('id', $runId)->update([
                'status' => 'failed',
                'error'  => mb_substr($exception->getMessage(), 0, 2000),
            ]))
            ->finally(fn (Batch $batch) => AiDescriptionRun::where('id', $runId)
                ->where('status', 'processing')
                ->update(['status' => 'completed', 'finished_at' => now()]))
            ->dispatch();
    }

    public function failed(Throwable $exception): void
    {
        AiDescriptionRun::where('id', $this->runId)->update([
            'status' => 'failed',
            'error'  => mb_substr($exception->getMessage(), 0, 2000),
        ]);
    }
}
