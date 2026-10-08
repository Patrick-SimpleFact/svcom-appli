<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** E-mails de l'espace salle (F9.1, F9.2) : accusé de réception, nouvelle demande, invitation, refus, précisions. */
class EspaceSalleMail extends Mailable
{
    public function __construct(public string $sujet, public string $vue, public array $donnees) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->sujet);
    }

    public function content(): Content
    {
        return new Content(markdown: $this->vue, with: $this->donnees);
    }
}
