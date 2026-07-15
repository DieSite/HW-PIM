<?php

namespace App\Console\Commands;

use App\Services\CompetitorCatalogExporter;
use Illuminate\Console\Command;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class RunCompetitorAnalysisCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'pricing:run-competitor-analysis
                            {--skip-scrape : Skip the Node scraper and only (re)import the existing SQLite DB}
                            {--no-recompute : Import competitor prices without recomputing/syncing our prices}';

    /**
     * @var string
     */
    protected $description = 'Run the full competitor-price pipeline: Node scraper (rebuild SQLite) then import + recompute + sync.';

    public function handle(): int
    {
        if (! $this->analysisEnabled()) {
            $this->warn('Concurrentie-analyse staat uit (Configuratie → Prijsstelling). Overgeslagen.');

            return self::SUCCESS;
        }

        if (! $this->option('skip-scrape') && ! $this->runScraper()) {
            return self::FAILURE;
        }

        return $this->call('pricing:import-competitor-prices', [
            '--no-recompute' => (bool) $this->option('no-recompute'),
        ]);
    }

    /**
     * Whether the competitor analysis is enabled in the admin configuration
     * (Configuration → Prijsstelling). Defaults to enabled when never set, so
     * existing installs keep running until an admin explicitly turns it off.
     */
    private function analysisEnabled(): bool
    {
        $configured = core()->getConfigData('general.pricing.settings.enabled');

        if ($configured === null || $configured === '') {
            return true;
        }

        return (bool) $configured;
    }

    /**
     * Run the browserless Node pipeline (`node catalog-volledig/run.js`) which
     * rebuilds the competitor SQLite database. The catalog it reads is exported
     * from the product database at runtime into a temporary CSV, so no manual
     * export is required. Returns false when it cannot / did not complete.
     */
    private function runScraper(): bool
    {
        $dir = (string) config('competitor_pricing.scraper_dir');

        if (! is_dir($dir)) {
            $this->error("Scraper directory not found: {$dir}");

            return false;
        }

        $csv = $this->exportCatalogCsv();

        if ($csv === null) {
            return false;
        }

        try {
            if (! is_dir($dir.'/node_modules')) {
                $this->info('Installing scraper dependencies (one-time)…');

                // --omit=dev skips the Playwright devDependency (and its Chromium
                // download): the `volledig` pipeline is plain Node HTTP.
                if (! $this->process(['npm', 'install', '--omit=dev'], $dir, 600)) {
                    return false;
                }
            }

            $this->info('Running competitor scraper (this can take several minutes)…');

            return $this->process(
                ['node', 'catalog-volledig/run.js'],
                $dir,
                (int) config('competitor_pricing.scraper_timeout'),
                [
                    'CATALOG_CSV' => $csv,
                    'CONCURRENCY' => (string) config('competitor_pricing.concurrency'),
                ],
            );
        } finally {
            @unlink($csv);
        }
    }

    /**
     * Export the PIM catalog (SKU,Merk,Model,Maat,Prijs) from the database to a
     * temporary CSV the Node scraper reads. Returns the temp file path, or null
     * when the export failed (reported to Sentry so it is never silent).
     */
    private function exportCatalogCsv(): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog_');

        try {
            $rows = app(CompetitorCatalogExporter::class)->export($path);
        } catch (\Throwable $e) {
            $this->error('Failed to export the catalog from the database: '.$e->getMessage());
            report($e);
            @unlink($path);

            return null;
        }

        $this->info("Exported {$rows} catalog rows to a temporary CSV.");

        return $path;
    }

    /**
     * @param  array<int, string>  $command
     * @param  array<string, string>  $env
     */
    private function process(array $command, string $cwd, int $timeout, array $env = []): bool
    {
        $process = new Process($command, $cwd, $env + ['PATH' => getenv('PATH')], null, $timeout);

        try {
            $process->mustRun(function (string $type, string $buffer): void {
                $this->output->write($buffer);
            });
        } catch (ProcessFailedException $e) {
            $this->error('Command failed: '.implode(' ', $command));
            report($e);

            return false;
        }

        return true;
    }
}
