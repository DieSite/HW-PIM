<?php

namespace App\Exports;

use App\Services\CompetitorCoverageAnalyzer;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel-werkboek met alle kleden die buiten de concurrentie-analyse vallen.
 *
 * Eerst een overzicht per fabrikant, dan één blad per fabrikant met diens
 * kleden, dan de volledige lijst en tot slot de telling per reden — zodat
 * meteen te zien is waar data-onderhoud het meeste oplevert en wat er per
 * definitie buiten valt.
 */
class CompetitorCoverageExport implements Export, WithMultipleSheets
{
    public const UNKNOWN_BRAND = 'Zonder merk';

    /**
     * De bladnamen die al vastliggen; een fabrikant mag er niet op botsen.
     */
    private const FIXED_SHEETS = ['Per fabrikant', 'Niet meegenomen', 'Samenvatting'];

    private readonly CoverageTally $tally;

    /**
     * De kleden, gegroepeerd per fabrikant. Pas gevuld bij het eerste gebruik:
     * het doorlopen van de catalogus duurt even.
     *
     * @var array<string, array<int, array<int, mixed>>>|null
     */
    private ?array $rowsByBrand = null;

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
        $rowsByBrand = $this->rowsByBrand();
        $headings = $this->analyzer->headings();
        $titles = self::FIXED_SHEETS;
        $sheets = [new CoverageByBrandSheet($this->analyzer, $this->tally)];

        foreach ($this->tally->brands() as $brand) {
            $title = $this->sheetTitle(self::brandLabel($brand), $titles);
            $titles[] = $title;

            $sheets[] = new UncoveredRugsSheet($title, $headings, $rowsByBrand[$brand]);
        }

        $sheets[] = new UncoveredRugsSheet('Niet meegenomen', $headings, array_merge(...array_values($rowsByBrand)));
        $sheets[] = new CoverageSummarySheet($this->analyzer, $this->tally);

        return $sheets;
    }

    /**
     * De telling per reden en per fabrikant.
     */
    public function tally(): CoverageTally
    {
        $this->rowsByBrand();

        return $this->tally;
    }

    public static function brandLabel(string $brand): string
    {
        return $brand !== '' ? $brand : self::UNKNOWN_BRAND;
    }

    /**
     * @return array<string, array<int, array<int, mixed>>>
     */
    private function rowsByBrand(): array
    {
        if ($this->rowsByBrand !== null) {
            return $this->rowsByBrand;
        }

        $this->rowsByBrand = [];

        foreach ($this->analyzer->uncovered() as $row) {
            if ($this->reasons !== [] && ! in_array($row['reden'], $this->reasons, true)) {
                continue;
            }

            $brand = trim($row['merk']);

            $this->tally->add($row['reden'], $brand);
            $this->rowsByBrand[$brand][] = array_values($row);
        }

        return $this->rowsByBrand;
    }

    /**
     * Excel staat in een bladnaam geen : \ / ? * [ ] toe, maximaal 31 tekens,
     * en elke naam moet uniek zijn (hoofdletterongevoelig).
     *
     * @param  array<int, string>  $taken
     */
    private function sheetTitle(string $label, array $taken): string
    {
        $base = mb_substr(trim(preg_replace('/[:\\\\\/?*\[\]]+/u', ' ', $label)), 0, 31);
        $base = $base !== '' ? $base : self::UNKNOWN_BRAND;
        $taken = array_map('mb_strtolower', $taken);

        $title = $base;

        for ($i = 2; in_array(mb_strtolower($title), $taken, true); $i++) {
            $suffix = " ({$i})";
            $title = mb_substr($base, 0, 31 - mb_strlen($suffix)).$suffix;
        }

        return $title;
    }
}
