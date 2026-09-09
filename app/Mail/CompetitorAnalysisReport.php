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
     * A wider layout than Laravel's default 570px.
     *
     * The report is built of five-column tables; at the default width every
     * cell wrapped over three lines, which is the very thing the tables were
     * meant to fix. Scoped to this mailable, so the other UnoPim mails keep the
     * standard theme.
     *
     * @var string
     */
    public $theme = 'hw';

    /**
     * @param  array<string, mixed>  $report  {@see CompetitorAnalysisReporter::build()}
     */
    public function __construct(public array $report) {}

    public function envelope(): Envelope
    {
        $alerts = (int) $this->report['alerts'];
        $warnings = (int) $this->report['warnings'];
        $actions = $this->report['actions'];

        $changes = (int) $this->report['changes']['total'];

        /**
         * Eerst wat er te dóén is, dan pas de omvang van de run. Het aantal
         * prijswijzigingen alléén zei niets — dat zijn er elke nacht honderden
         * zonder dat er één van hoeft te worden aangeraakt — maar naast de
         * acties geeft het wel de schaal waarin die acties staan.
         */
        $parts = array_filter([
            count($actions['suspects']['items']) > 0
                ? count($actions['suspects']['items']).' te controleren'
                : null,
            $actions['new_products']['total'] > 0
                ? $actions['new_products']['total'].' nieuw'
                : null,
            $actions['lost_prices']['total'] > 0
                ? $actions['lost_prices']['total'].' koppeling'.($actions['lost_prices']['total'] === 1 ? '' : 'en').' weg'
                : null,
            $changes.' '.($changes === 1 ? 'prijswijziging' : 'prijswijzigingen'),
        ]);

        $subject = 'Concurrentie-analyse vloerkleden – '
            .$this->report['until']->copy()->timezone('Europe/Amsterdam')->format('d-m-Y')
            .' ('.implode(', ', $parts).')';

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

        $actionRows = array_sum(array_map(
            fn (array $block): int => count($block['items']),
            $this->report['actions'],
        ));

        if ($actionRows > 0) {
            $acties = $reporter->actionsToCsv($this->report['actions']);

            $attachments[] = Attachment::fromData(fn (): string => $acties, 'acties-'.$date.'.csv')
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
