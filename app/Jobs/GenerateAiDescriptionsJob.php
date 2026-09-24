<?php

namespace App\Jobs;

use App\Jobs\Middleware\DisconnectsIdleRedis;
use App\Models\AiDescriptionRun;
use App\Services\AI\AiDescriptionService;
use Diesite\Monitor\Monitor;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
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

    /**
     * Fail fast: one attempt and no retryUntil() deadline. Running the fan-out
     * a second time would queue every product twice.
     */
    public $tries = 1;

    public $failOnTimeout = true;

    public function __construct(public readonly int $runId)
    {
        $this->onConnection('redis-ai');
        $this->onQueue('ai');
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
            /**
             * Fires on the first product job that fails at queue level (a
             * timeout, a dead worker), while the rest of the batch is still
             * queued. The run keeps "processing" until finally(): marking it
             * failed here made it look finished, and the next run then sat at
             * 0 done behind this one's remaining jobs on the shared queue.
             */
            ->catch(fn (Batch $batch, Throwable $exception) => AiDescriptionRun::where('id', $runId)->update([
                'error' => mb_substr($exception->getMessage(), 0, 2000),
            ]))
            ->finally(function (Batch $batch) use ($runId): void {
                AiDescriptionRun::where('id', $runId)
                    ->where('status', 'processing')
                    ->update(['status' => 'completed', 'finished_at' => now()]);

                $run = AiDescriptionRun::find($runId);

                Monitor::positive(
                    'AI-teksten klaar',
                    sprintf(
                        'Run #%d · %d concepten klaar voor beoordeling · %d mislukt',
                        $runId,
                        (int) $run?->generated_count,
                        (int) $run?->failed_count
                    ),
                    '🤖'
                );
            })
            ->dispatch();
    }

    public function failed(Throwable $exception): void
    {
        AiDescriptionRun::where('id', $this->runId)->update([
            'status' => 'failed',
            'error'  => mb_substr($exception->getMessage(), 0, 2000),
        ]);

        Monitor::negative("AI-teksten run #{$this->runId} mislukt", Str::limit($exception->getMessage(), 120), '🤖');
    }
}
