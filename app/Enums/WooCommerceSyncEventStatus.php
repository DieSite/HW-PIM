<?php

namespace App\Enums;

enum WooCommerceSyncEventStatus: string
{
    case Queued = 'queued';
    case Started = 'started';
    case Success = 'success';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Queued  => 'In wachtrij',
            self::Started => 'Bezig',
            self::Success => 'Gelukt',
            self::Failed  => 'Mislukt',
            self::Skipped => 'Overgeslagen',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Success => 'success',
            self::Failed  => 'danger',
            self::Started => 'warning',
            self::Queued  => 'info',
            self::Skipped => 'default',
        };
    }

    /**
     * Whether the sync is still expected to progress, i.e. the panel should
     * keep polling for a newer event.
     */
    public function isInFlight(): bool
    {
        return in_array($this, [self::Queued, self::Started], true);
    }
}
