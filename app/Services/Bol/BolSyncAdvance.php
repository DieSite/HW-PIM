<?php

namespace App\Services\Bol;

class BolSyncAdvance
{
    private function __construct(
        public readonly bool $isTerminal,
        public readonly ?string $pollProcessId = null,
        public readonly int $pollDelaySeconds = BolSyncStateMachine::POLL_DELAY_SECONDS,
    ) {}

    public static function terminal(): self
    {
        return new self(isTerminal: true);
    }

    public static function poll(string $processId, ?int $delaySeconds = null): self
    {
        return new self(
            isTerminal: false,
            pollProcessId: $processId,
            pollDelaySeconds: $delaySeconds ?? BolSyncStateMachine::POLL_DELAY_SECONDS,
        );
    }
}
