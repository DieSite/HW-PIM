<?php

namespace App\Services\Bol;

class ValidationResult
{
    /** @param  ValidationFailure[]  $failures */
    public function __construct(
        public readonly array $failures = [],
        public readonly ?string $normalizedEan = null,
    ) {}

    public function passed(): bool
    {
        return $this->failures === [];
    }

    public function failed(): bool
    {
        return ! $this->passed();
    }

    public function firstCustomerMessage(): ?string
    {
        return $this->failures[0]->customerMessage ?? null;
    }

    public function customerSummary(): string
    {
        return collect($this->failures)
            ->map(fn (ValidationFailure $f) => $f->customerMessage)
            ->implode(' ');
    }

    /** @return array<int, array<string, string>> */
    public function toArray(): array
    {
        return array_map(fn (ValidationFailure $f) => $f->toArray(), $this->failures);
    }
}
