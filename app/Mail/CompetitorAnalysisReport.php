<?php

namespace App\Mail;

use App\Services\CompetitorAnalysisReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CompetitorAnalysisReport extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $report  {@see CompetitorAnalysisReporter::build()}
     */
    public function __construct(public array $report) {}

    public function envelope(): Envelope
    {
        $changes = (int) $this->report['changes']['total'];
        $outliers = (int) $this->report['outlier_total'];
        $alerts = (int) $this->report['alerts'];
        $warnings = (int) $this->report['warnings'];

        $subject = 'Concurrentie-analyse vloerkleden – '
            .$this->report['until']->copy()->timezone('Europe/Amsterdam')->format('d-m-Y')
            .' ('.$changes.' '.($changes === 1 ? 'prijswijziging' : 'prijswijzigingen');

        if ($outliers > 0) {
            $subject .= ', '.$outliers.' '.($outliers === 1 ? 'uitschieter' : 'uitschieters');
        }

        $subject .= ')';

        /**
         * Een alarm hoort in de onderwerpregel: wie de mail alleen scant moet
         * zien dat er iets stuk is zonder hem te openen.
         */
        if ($alerts > 0) {
            $subject = '⚠ '.$subject.' – '.$alerts.' '.($alerts === 1 ? 'alarm' : 'alarmen');
        } elseif ($warnings > 0) {
            $subject .= ' – '.$warnings.' '.($warnings === 1 ? 'signaal' : 'signalen');
        }

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.pricing.competitor-report',
            with: [
                'reporter'   => app(CompetitorAnalysisReporter::class),
                'maxRows'    => (int) $this->report['thresholds']['max_rows'],
                'thresholds' => $this->report['thresholds'],
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $reporter = app(CompetitorAnalysisReporter::class);
        $date = $this->report['until']->copy()->timezone('Europe/Amsterdam')->format('Y-m-d');

        $attachments = [];

        if ($this->report['rows'] !== []) {
            $csv = $reporter->toCsv($this->report['rows']);

            $attachments[] = Attachment::fromData(fn (): string => $csv, 'prijswijzigingen-'.$date.'.csv')
                ->withMime('text/csv');
        }

        if ($this->report['flagged'] > 0) {
            $checks = $reporter->checksToCsv($this->report['checks']);

            $attachments[] = Attachment::fromData(fn (): string => $checks, 'aandachtspunten-'.$date.'.csv')
                ->withMime('text/csv');
        }

        return $attachments;
    }
}
