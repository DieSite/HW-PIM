<?php

namespace App\Enums;

enum BolSyncStep: string
{
    case Validation = 'validation';
    case SubmitContent = 'submit_content';
    case PollContent = 'poll_content';
    case SubmitOffer = 'submit_offer';
    case PollOffer = 'poll_offer';
    case Retire = 'retire';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Validation    => 'Validatie',
            self::SubmitContent => 'Productinformatie versturen',
            self::PollContent   => 'Status productinformatie',
            self::SubmitOffer   => 'Aanbod aanmelden',
            self::PollOffer     => 'Status aanbod',
            self::Retire        => 'Verwijderen',
            self::Manual        => 'Handmatig',
        };
    }
}
