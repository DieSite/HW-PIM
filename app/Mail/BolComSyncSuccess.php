<?php

namespace App\Mail;

use App\Models\BolComCredential;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Webkul\Product\Models\Product;

class BolComSyncSuccess extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Product $product,
        public array $offer,
        public BolComCredential $bolComCredential
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        if (! empty($this->offer['notPublishableReasons'])) {
            return new Envelope(
                subject: 'Bol.com Sync actie vereist'
            );
        } else {
            return new Envelope(
                subject: 'Bol.com Sync succesvol'
            );
        }
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.bolcom.sync-success',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
