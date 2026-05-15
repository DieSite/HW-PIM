<?php

namespace App\Enums;

enum BolSyncEventStatus: string
{
    case Started = 'started';
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
