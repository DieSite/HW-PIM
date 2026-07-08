<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HordeurenAnalysisFailed extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $error) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Concurrentie-analyse hordeuren mislukt',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.hordeuren.failed',
        );
    }
}
