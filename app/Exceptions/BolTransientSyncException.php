<?php

namespace App\Exceptions;

use App\Enums\BolSyncStep;
use RuntimeException;
use Throwable;

/**
 * Signals that a Bol.com sync step failed with a transient error (5xx, 429,
 * or a network hiccup). The state machine throws this instead of terminally
 * failing so the queue job can retry; only once retries are exhausted is the
 * failure recorded and the customer emailed.
 */
class BolTransientSyncException extends RuntimeException
{
    public function __construct(
        public readonly BolSyncStep $step,
        Throwable $previous,
    ) {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}
