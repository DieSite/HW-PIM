<?php

namespace App\Exports;

/**
 * Telt hoeveel kleden er per reden — en per fabrikant per reden — buiten de
 * analyse vallen, zodat de overzichtsbladen geen tweede ronde door de
 * catalogus hoeven te doen.
 */
class CoverageTally
{
    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, array<string, int>> */
    private array $brandCounts = [];

    public function add(string $reason, string $brand = ''): void
    {
        $this->counts[$reason] = ($this->counts[$reason] ?? 0) + 1;
        $this->brandCounts[$brand][$reason] = ($this->brandCounts[$brand][$reason] ?? 0) + 1;
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

    public function countFor(string $brand, string $reason): int
    {
        return $this->brandCounts[$brand][$reason] ?? 0;
    }

    public function totalFor(string $brand): int
    {
        return array_sum($this->brandCounts[$brand] ?? []);
    }

    /**
     * De fabrikanten, de grootste groep niet-meegenomen kleden eerst. Kleden
     * zonder merk (lege string) komen altijd als laatste.
     *
     * @return array<int, string>
     */
    public function brands(): array
    {
        $brands = array_map('strval', array_keys($this->brandCounts));

        usort($brands, fn (string $a, string $b): int => [$a === '', $this->totalFor($b), $a] <=> [$b === '', $this->totalFor($a), $b]);

        return $brands;
    }
}
