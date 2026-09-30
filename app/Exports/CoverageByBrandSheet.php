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
 * Het blad dat per fabrikant telt hoeveel kleden buiten de analyse vallen,
 * uitgesplitst naar reden — de grootste groep bovenaan.
 */
class CoverageByBrandSheet implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    public function __construct(
        private readonly CompetitorCoverageAnalyzer $analyzer,
        private readonly CoverageTally $tally,
    ) {}

    public function title(): string
    {
        return 'Per fabrikant';
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Fabrikant', 'Totaal', ...$this->reasons()];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        $rows = [];

        foreach ($this->tally->brands() as $brand) {
            $rows[] = [
                CompetitorCoverageExport::brandLabel($brand),
                $this->tally->totalFor($brand),
                ...array_map(fn (string $reason): int => $this->tally->countFor($brand, $reason), $this->reasons()),
            ];
        }

        $rows[] = [
            'Totaal',
            $this->tally->total(),
            ...array_map(fn (string $reason): int => $this->tally->count($reason), $this->reasons()),
        ];

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('B2');

        return [
            1                                 => ['font' => ['bold' => true]],
            count($this->tally->brands()) + 2 => ['font' => ['bold' => true]],
        ];
    }

    /**
     * Alleen de redenen die ergens voorkomen, zodat er geen kolommen vol
     * nullen in staan.
     *
     * @return array<int, string>
     */
    private function reasons(): array
    {
        return array_values(array_filter(
            $this->analyzer->reasons(),
            fn (string $reason): bool => $this->tally->count($reason) > 0,
        ));
    }
}
