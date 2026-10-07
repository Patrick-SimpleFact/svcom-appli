<?php

namespace App\Mail;

use App\Comptes\CodesConnexion;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** E-mail du code de connexion (F1.4). */
class CodeConnexionMail extends Mailable
{
    public function __construct(public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Votre code Spettacoli : {$this->code}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.code-connexion', with: ['minutes' => CodesConnexion::VALIDITE_MINUTES]);
    }
}
