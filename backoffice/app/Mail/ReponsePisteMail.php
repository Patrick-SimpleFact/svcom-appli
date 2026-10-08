<?php

namespace App\Mail;

use App\Enums\StatutPiste;
use App\Models\Piste;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Réponse à une piste (F8.6) : dans tous les cas, avec la raison en cas de refus. */
class ReponsePisteMail extends Mailable
{
    public function __construct(public Piste $piste) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->piste->statut === StatutPiste::Integree
            ? "Bonne nouvelle : « {$this->piste->nom} » est dans Spettacoli"
            : "Votre proposition « {$this->piste->nom} »");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.reponse-piste');
    }
}
