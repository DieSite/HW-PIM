<?php

namespace App\Exports;

use App\Services\CompetitorCoverageAnalyzer;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel-werkboek met alle kleden die buiten de concurrentie-analyse vallen.
 *
 * Blad 1 is de lijst zelf, blad 2 telt per reden hoeveel kleden het betreft —
 * zodat meteen te zien is wat data-onderhoud oplevert en wat er per definitie
 * buiten valt.
 */
class CompetitorCoverageExport implements WithMultipleSheets
{
    /**
     * Eén teller, gedeeld door beide bladen. Het samenvattingsblad komt als
     * tweede en leest hem dus pas als het eerste blad al zijn rijen heeft
     * doorgegeven — Excel schrijft de bladen op volgorde weg.
     */
    private readonly CoverageTally $tally;

    /**
     * @param  array<int, string>  $reasons  Alleen deze redenen exporteren (leeg = alles).
     */
    public function __construct(
        private readonly CompetitorCoverageAnalyzer $analyzer,
        private readonly array $reasons = [],
    ) {
        $this->tally = new CoverageTally();
    }

    /**
     * @return array<int, object>
     */
    public function sheets(): array
    {
        return [
            new UncoveredRugsSheet($this->analyzer, $this->reasons, $this->tally),
            new CoverageSummarySheet($this->analyzer, $this->tally),
        ];
    }

    /**
     * De telling per reden, gevuld tijdens het wegschrijven.
     */
    public function tally(): CoverageTally
    {
        return $this->tally;
    }
}
