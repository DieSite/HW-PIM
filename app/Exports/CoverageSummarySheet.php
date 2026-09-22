<?php

namespace App\Exports;

use App\Services\CompetitorCoverageAnalyzer;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Het blad dat per reden telt hoeveel kleden buiten de analyse vallen.
 */
class CoverageSummarySheet implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    public function __construct(
        private readonly CompetitorCoverageAnalyzer $analyzer,
        private readonly CoverageTally $tally,
    ) {}

    public function title(): string
    {
        return 'Samenvatting';
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Reden', 'Aantal kleden', 'Toelichting'];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        $rows = [];

        foreach ($this->analyzer->reasons() as $reason) {
            $count = $this->tally->count($reason);

            if ($count === 0) {
                continue;
            }

            $rows[] = [$reason, $count, $this->analyzer->explain($reason)];
        }

        $rows[] = ['Totaal', $this->tally->total(), ''];

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
