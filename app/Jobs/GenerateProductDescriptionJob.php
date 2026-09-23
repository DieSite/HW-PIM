<?php

namespace App\Jobs;

use App\Jobs\Middleware\DisconnectsIdleRedis;
use App\Models\AiDescriptionDraft;
use App\Models\AiDescriptionRun;
use App\Models\Product;
use App\Services\AI\AiDescriptionService;
use App\Services\AI\ProductDescriptionGenerator;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes one product's texts into a draft. Nothing reaches the shop here — a
 * human approves first.
 */
class GenerateProductDescriptionJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Outlasts the slowest the model call may legitimately take: two
     * generator attempts, each up to ai.request.retries HTTP tries of
     * ai.request.timeout seconds. A shorter timeout makes the worker kill
     * itself mid-call, which leaves the job reserved until retry_after (an
     * hour) with nothing in the logs.
     */
    public $timeout = 600;

    /**
     * Fail fast: one attempt and no retryUntil() deadline. A worker that dies
     * mid-call brings the job back after retry_after, and that second
     * reservation fails it on the spot instead of quietly running it again.
     * No deadline also means a product that waits hours behind the rest of
     * its run still gets its turn.
     */
    public $tries = 1;

    /**
     * A timeout fails the product right away, with a draft that says so,
     * instead of leaving it reserved until retry_after.
     */
    public $failOnTimeout = true;

    /**
     * @param  list<string>  $fields
     */
    public function __construct(
        public readonly int $productId,
        public readonly array $fields,
        public readonly ?int $runId = null,
    ) {
        $this->onConnection('redis-ai');
        $this->onQueue('ai');
    }

    /**
     * Spends most of its time waiting on the model's HTTP response without
     * touching Redis. {@see DisconnectsIdleRedis}
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new DisconnectsIdleRedis()];
    }

    public function handle(
        ProductDescriptionGenerator $generator,
        AiDescriptionService $descriptions,
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $product = Product::find($this->productId);

        if (! $product) {
            $this->recordFailure("Product [{$this->productId}] bestaat niet meer.");

            return;
        }

        try {
            $result = $generator->generate($product, $this->fields);
        } catch (Throwable $exception) {
            $this->recordFailure($exception->getMessage());

            Log::warning("AI-tekst mislukt voor product [{$this->productId}]: {$exception->getMessage()}");

            return;
        }

        AiDescriptionDraft::updateOrCreate(
            ['product_id' => $this->productId, 'run_id' => $this->runId],
            [
                'status'          => AiDescriptionDraft::STATUS_PENDING,
                'fields'          => $result['texts'],
                'previous_values' => $descriptions->snapshot($product, array_keys($result['texts'])),
                'problems'        => $result['problems'],
                'similarity'      => $result['similarity'],
                'driver'          => config('ai.driver'),
                'model'           => $result['model'],
                'prompt_version'  => config('ai.prompt_version'),
                'input_tokens'    => $result['input_tokens'],
                'output_tokens'   => $result['output_tokens'],
                'attempts'        => $result['attempts'],
                'error'           => null,
            ],
        );

        if ($this->runId !== null) {
            AiDescriptionRun::where('id', $this->runId)->increment('generated_count');
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->recordFailure($exception->getMessage());
    }

    private function recordFailure(string $message): void
    {
        AiDescriptionDraft::updateOrCreate(
            ['product_id' => $this->productId, 'run_id' => $this->runId],
            [
                'status' => AiDescriptionDraft::STATUS_FAILED,
                'error'  => mb_substr($message, 0, 2000),
            ],
        );

        if ($this->runId !== null) {
            AiDescriptionRun::where('id', $this->runId)->increment('failed_count');
        }
    }
}
