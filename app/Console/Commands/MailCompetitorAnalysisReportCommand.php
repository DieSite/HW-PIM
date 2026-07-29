<?php

namespace App\Console\Commands;

use App\Mail\CompetitorAnalysisReport;
use App\Services\CompetitorAnalysisReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class MailCompetitorAnalysisReportCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'pricing:mail-competitor-report
                            {--since= : Start of the reporting window (default: today 00:00)}
                            {--until= : End of the reporting window (default: now)}
                            {--to=* : Override the configured recipients}
                            {--dry-run : Print the summary instead of mailing it}';

    /**
     * @var string
     */
    protected $description = 'Mail a report of the competitor analysis: which prices changed, why, and which rows are outliers.';

    public function handle(CompetitorAnalysisReporter $reporter): int
    {
        $since = $this->parseDate('since', now()->startOfDay());
        $until = $this->parseDate('until', now());

        if ($since === null || $until === null) {
            return self::FAILURE;
        }

        $report = $reporter->build($since, $until);

        $this->summarize($report);

        if ($this->option('dry-run')) {
            $this->info('Dry-run: geen mail verstuurd.');

            return self::SUCCESS;
        }

        $recipients = $this->recipients();

        if ($recipients === []) {
            $this->error('Geen ontvangers ingesteld (competitor_pricing.report.recipients of --to).');

            return self::FAILURE;
        }

        Mail::to($recipients)->send(new CompetitorAnalysisReport($report));

        $this->info('Rapport verstuurd naar '.implode(', ', $recipients).'.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function recipients(): array
    {
        $override = array_filter(array_map('trim', (array) $this->option('to')));

        if ($override !== []) {
            return array_values($override);
        }

        return array_values(array_filter(array_map(
            'trim',
            (array) config('competitor_pricing.report.recipients', [])
        )));
    }

    private function parseDate(string $option, Carbon $default): ?Carbon
    {
        $value = $this->option($option);

        if ($value === null || $value === '') {
            return $default;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable $e) {
            $this->error("Ongeldige --{$option} waarde: {$value}");

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function summarize(array $report): void
    {
        $changes = $report['changes'];

        $this->info(sprintf(
            '%d prijswijzigingen (%d omlaag, %d omhoog) op %d varianten tussen %s en %s.',
            $changes['total'],
            $changes['down'],
            $changes['up'],
            $changes['products'],
            $report['since']->format('d-m-Y H:i'),
            $report['until']->format('d-m-Y H:i'),
        ));

        foreach ($report['outliers'] as $group => $rows) {
            if ($rows !== []) {
                $this->warn(sprintf('Uitschieters — %s: %d', $group, count($rows)));
            }
        }
    }
}
