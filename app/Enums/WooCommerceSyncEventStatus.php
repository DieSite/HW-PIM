<?php

namespace App\Enums;

enum WooCommerceSyncEventStatus: string
{
    case Started = 'started';
    case Success = 'success';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
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
            self::Skipped => 'default',
        };
    }
}
