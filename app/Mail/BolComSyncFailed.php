<?php

namespace App\Mail;

use App\Models\BolComCredential;
use App\Models\BolSyncEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Webkul\Product\Models\Product;

class BolComSyncFailed extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Product $product,
        public BolSyncEvent $event,
        public BolComCredential $bolComCredential,
    ) {
        $this->onQueue('bolcom');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Bol.com synchronisatie mislukt: %s', $this->product->sku),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.bolcom.sync-failed',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
