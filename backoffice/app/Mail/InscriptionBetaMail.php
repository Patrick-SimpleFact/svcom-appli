<?php

namespace App\Mail;

use App\Web\ListeAttenteBeta;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Confirmation d'inscription à la bêta (double opt-in, W05b). */
class InscriptionBetaMail extends Mailable
{
    public function __construct(public string $lienConfirmation, public string $lienDesinscription) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirmez votre inscription à la bêta de Spettacoli');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.inscription-beta', with: ['jours' => ListeAttenteBeta::JOURS_LIEN]);
    }
}
