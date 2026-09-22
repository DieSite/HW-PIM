<?php

namespace App\Console\Commands;

use App\Exports\CompetitorCoverageExport;
use App\Services\CompetitorCoverageAnalyzer;
use Illuminate\Console\Command;
use Maatwebsite\Excel\Facades\Excel;

class ExportCompetitorCoverageCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'pricing:export-uncovered-rugs
                            {--output= : Pad van het Excel-bestand (standaard storage/app/concurrentie-analyse-niet-meegenomen-<datum>.xlsx)}
                            {--reason=* : Alleen deze reden(en) exporteren, bijv. --reason="Geen merk"}';

    /**
     * @var string
     */
    protected $description = 'Exporteer naar Excel welke kleden niet in de concurrentie-analyse zitten, met per kleed de reden.';

    public function handle(CompetitorCoverageAnalyzer $analyzer): int
    {
        $reasons = $this->reasons($analyzer);

        if ($reasons === null) {
            return self::FAILURE;
        }

        $path = $this->outputPath();

        $this->info('Kleden doorlopen… (dit duurt even bij een volle catalogus)');

        $export = new CompetitorCoverageExport($analyzer, $reasons);

        /**
         * Excel schrijft naar een disk, niet naar een pad. Een disk op de
         * doelmap laat --output dus gewoon elk pad aanwijzen, zonder het
         * bestand eerst ergens anders neer te zetten en te verplaatsen.
         */
        config(['filesystems.disks.competitor_coverage' => [
            'driver' => 'local',
            'root'   => dirname($path),
        ]]);

        Excel::store($export, basename($path), 'competitor_coverage');

        $this->summarize($analyzer, $export);

        $this->info('Export geschreven naar: '.$path);

        return self::SUCCESS;
    }

    /**
     * De gevraagde redenen, gevalideerd tegen wat de analyse kent. Een typefout
     * zou anders een leeg bestand opleveren dat eruitziet als goed nieuws.
     *
     * @return array<int, string>|null  null bij een onbekende reden
     */
    private function reasons(CompetitorCoverageAnalyzer $analyzer): ?array
    {
        $requested = array_values(array_filter(array_map('trim', (array) $this->option('reason'))));

        foreach ($requested as $reason) {
            if (! in_array($reason, $analyzer->reasons(), true)) {
                $this->error("Onbekende reden: {$reason}");
                $this->line('Bekende redenen: '.implode(' · ', $analyzer->reasons()));

                return null;
            }
        }

        return $requested;
    }

    private function outputPath(): string
    {
        $output = (string) ($this->option('output') ?? '');

        if ($output !== '') {
            return $output;
        }

        return storage_path('app/concurrentie-analyse-niet-meegenomen-'.now()->format('Y-m-d').'.xlsx');
    }

    private function summarize(CompetitorCoverageAnalyzer $analyzer, CompetitorCoverageExport $export): void
    {
        $tally = $export->tally();

        if ($tally->total() === 0) {
            $this->warn('Geen enkel kleed valt buiten de analyse — controleer of de catalogus gevuld is.');

            return;
        }

        $this->table(
            ['Reden', 'Aantal'],
            collect($analyzer->reasons())
                ->map(fn (string $reason): array => [$reason, $tally->count($reason)])
                ->filter(fn (array $row): bool => $row[1] > 0)
                ->push(['Totaal', $tally->total()])
                ->all(),
        );
    }
}
