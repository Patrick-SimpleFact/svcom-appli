<?php

namespace App\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** « Recevoir mes données » (F1.7, RGPD) : les données du compte en pièce jointe (JSON). */
class ExportDonneesMail extends Mailable
{
    public function __construct(public array $donnees) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Vos données Spettacoli');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.export-donnees');
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => json_encode($this->donnees, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'mes-donnees-spettacoli.json')->withMime('application/json')];
    }
}
