<?php

namespace App\Exports;

/**
 * Telt tijdens het wegschrijven hoeveel kleden er per reden buiten de analyse
 * vallen, zodat het samenvattingsblad geen tweede ronde door de catalogus
 * hoeft te doen.
 */
class CoverageTally
{
    /** @var array<string, int> */
    private array $counts = [];

    public function add(string $reason): void
    {
        $this->counts[$reason] = ($this->counts[$reason] ?? 0) + 1;
    }

    public function count(string $reason): int
    {
        return $this->counts[$reason] ?? 0;
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }

    /**
     * @return array<string, int>
     */
    public function all(): array
    {
        return $this->counts;
    }
}
