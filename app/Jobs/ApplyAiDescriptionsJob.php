<?php

namespace App\Jobs;

use App\Jobs\Middleware\DisconnectsIdleRedis;
use App\Models\AiDescriptionDraft;
use App\Services\AI\AiDescriptionService;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes approved drafts: writes the texts onto the products and queues the
 * WooCommerce sync. Each draft keeps the values it replaced, so a publish stays
 * reversible.
 */
class ApplyAiDescriptionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800;

    public $maxExceptions = 1;

    /**
     * @param  list<int>  $draftIds
     */
    public function __construct(
        public readonly array $draftIds,
        public readonly bool $syncWoo = true,
    ) {
        $this->onConnection('redis-ai');
        $this->onQueue('ai');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(45);
    }

    /**
     * Writes products one by one with no Redis traffic of its own between the
     * sync dispatches. {@see DisconnectsIdleRedis}
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new DisconnectsIdleRedis()];
    }

    public function handle(AiDescriptionService $descriptions): void
    {
        AiDescriptionDraft::query()
            ->whereIn('id', $this->draftIds)
            ->where('status', AiDescriptionDraft::STATUS_APPROVED)
            ->chunkById(100, function ($drafts) use ($descriptions) {
                foreach ($drafts as $draft) {
                    try {
                        $descriptions->publish($draft, $this->syncWoo);
                    } catch (Throwable $exception) {
                        $draft->update([
                            'status' => AiDescriptionDraft::STATUS_FAILED,
                            'error'  => mb_substr($exception->getMessage(), 0, 2000),
                        ]);

                        Log::warning("AI-tekst publiceren mislukt voor concept [{$draft->id}]: {$exception->getMessage()}");
                    }
                }
            });
    }
}
