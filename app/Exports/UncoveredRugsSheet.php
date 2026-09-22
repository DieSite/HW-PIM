<?php

namespace App\Exports;

use App\Services\CompetitorCoverageAnalyzer;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Het blad met één regel per kleed dat niet in de concurrentie-analyse zit.
 */
class UncoveredRugsSheet implements FromGenerator, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param  array<int, string>  $reasons
     */
    public function __construct(
        private readonly CompetitorCoverageAnalyzer $analyzer,
        private readonly array $reasons,
        private readonly CoverageTally $tally,
    ) {}

    public function title(): string
    {
        return 'Niet meegenomen';
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return $this->analyzer->headings();
    }

    public function generator(): \Generator
    {
        foreach ($this->analyzer->uncovered() as $row) {
            if ($this->reasons !== [] && ! in_array($row['reden'], $this->reasons, true)) {
                continue;
            }

            $this->tally->add($row['reden']);

            yield array_values($row);
        }
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'G' => NumberFormat::FORMAT_CURRENCY_EUR_SIMPLE,
            'H' => NumberFormat::FORMAT_CURRENCY_EUR_SIMPLE,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');

        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
