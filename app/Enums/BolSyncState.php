<?php

namespace App\Enums;

enum BolSyncState: string
{
    case Idle = 'idle';
    case Validating = 'validating';
    case SubmittingContent = 'submitting_content';
    case AwaitingContentMatch = 'awaiting_content_match';
    case SubmittingOffer = 'submitting_offer';
    case AwaitingOfferPublish = 'awaiting_offer_publish';
    case Live = 'live';
    case Failed = 'failed';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Idle                 => 'Niet gesynchroniseerd',
            self::Validating           => 'Controle van productgegevens',
            self::SubmittingContent    => 'Productinformatie wordt verstuurd',
            self::AwaitingContentMatch => 'Wacht op koppeling met Bol-catalogus',
            self::SubmittingOffer      => 'Aanbod wordt aangemeld',
            self::AwaitingOfferPublish => 'Wacht op publicatie van aanbod',
            self::Live                 => 'Live op Bol.com',
            self::Failed               => 'Synchronisatie mislukt',
            self::Retired              => 'Verwijderd van Bol.com',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Live    => 'success',
            self::Failed  => 'danger',
            self::Retired => 'neutral',
            self::Idle    => 'neutral',
            default       => 'warning',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Live, self::Failed, self::Retired, self::Idle], true);
    }
}
