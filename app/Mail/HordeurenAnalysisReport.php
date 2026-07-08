<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class HordeurenAnalysisReport extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{shops: int, cells: int, priced: int, missing: int}|null  $summary
     */
    public function __construct(
        public string $reportPath,
        public ?array $summary,
        public bool $hadFailures,
        public Carbon $startedAt,
        public Carbon $finishedAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Concurrentie-analyse hordeuren – '.$this->finishedAt->format('d-m-Y'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.hordeuren.report',
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromPath($this->reportPath)
                ->as('prijsvergelijking-plisse-hordeuren-'.$this->finishedAt->format('Y-m-d').'.xlsx')
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
    }
}
