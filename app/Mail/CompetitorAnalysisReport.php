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

        $subject = 'Concurrentie-analyse vloerkleden – '
            .$this->report['until']->copy()->timezone('Europe/Amsterdam')->format('d-m-Y')
            .' ('.$changes.' '.($changes === 1 ? 'prijswijziging' : 'prijswijzigingen');

        if ($outliers > 0) {
            $subject .= ', '.$outliers.' '.($outliers === 1 ? 'uitschieter' : 'uitschieters');
        }

        return new Envelope(subject: $subject.')');
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
        if ($this->report['rows'] === []) {
            return [];
        }

        $csv = app(CompetitorAnalysisReporter::class)->toCsv($this->report['rows']);

        return [
            Attachment::fromData(fn (): string => $csv, 'prijswijzigingen-'
                .$this->report['until']->copy()->timezone('Europe/Amsterdam')->format('Y-m-d').'.csv')
                ->withMime('text/csv'),
        ];
    }
}
