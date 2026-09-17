<?php

namespace App\Monitor;

use App\Enums\BolSyncState;
use App\Enums\WooCommerceSyncEventStatus;
use App\Models\AiDescriptionDraft;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\WooCommerceSyncEvent;
use Illuminate\Support\Facades\Cache;

/**
 * Spotlight tiles for the DieSite TV board: catalogue size plus the sync,
 * pricing and AI-review signals that need attention. Cached briefly because
 * the board polls every few seconds.
 */
class PimHighlights
{
    public const CACHE_KEY = 'diesite-monitor:pim-highlights';

    /**
     * @return array<int, array{label: string, value: int, format: string}>
     */
    public function __invoke(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, fn (): array => $this->collect());
    }

    /**
     * @return array<int, array{label: string, value: int, format: string}>
     */
    public function collect(): array
    {
        $settledBolStates = [
            BolSyncState::Idle->value,
            BolSyncState::Live->value,
            BolSyncState::Failed->value,
            BolSyncState::Retired->value,
        ];

        return [
            $this->tile('Actieve producten', Product::query()->where('status', true)->whereNull('parent_id')->count()),
            $this->tile('Sync-fouten', Product::query()->whereRaw("JSON_EXTRACT(additional, '$.product_sync_error') IS NOT NULL")->count()),
            $this->tile('Live op Bol', Product::query()->where('bol_sync_state', BolSyncState::Live->value)->count()),
            $this->tile('Bol mislukt', Product::query()->where('bol_sync_state', BolSyncState::Failed->value)->count()),
            $this->tile('Bol vastgelopen', Product::query()
                ->whereNotNull('bol_sync_state')
                ->whereNotIn('bol_sync_state', $settledBolStates)
                ->where('bol_sync_state_at', '<', now()->subHour())
                ->count()),
            $this->tile('WC-fouten vandaag', WooCommerceSyncEvent::query()
                ->where('status', WooCommerceSyncEventStatus::Failed->value)
                ->where('created_at', '>=', today())
                ->count()),
            $this->tile('Prijswijzigingen vandaag', ProductPriceHistory::query()->where('changed_at', '>=', today())->count()),
            $this->tile('AI-concepten te beoordelen', AiDescriptionDraft::query()->where('status', AiDescriptionDraft::STATUS_PENDING)->count()),
        ];
    }

    /**
     * @return array{label: string, value: int, format: string}
     */
    private function tile(string $label, int $value): array
    {
        return ['label' => $label, 'value' => $value, 'format' => 'number'];
    }
}
