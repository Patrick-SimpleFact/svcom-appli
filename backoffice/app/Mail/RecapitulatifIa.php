<?php

namespace App\Mail;

use App\Filament\Pages\BoiteDeTravail;
use App\Filament\Resources\DecisionsIa\DecisionIaResource;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * E-mail de 8 h (plan-module-ia v2) : le nombre de décisions qui attendent Patrick, avec le lien direct,
 * ce que l'assistant IA a fait depuis la veille, et l'état du forfait Ollama.
 */
class RecapitulatifIa extends Mailable
{
    use Queueable;

    /**
     * @param  list<array{file: string, appliquees: int, proposees: int, doutes: int}>  $activite
     * @param  list<string>  $forfait
     */
    public function __construct(
        public int $actions,
        public int $enExamen,
        public array $activite,
        public array $forfait,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->actions > 0
            ? "Spettacoli : {$this->actions} action(s) à faire aujourd’hui"
            : 'Spettacoli : rien à faire aujourd’hui');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.recapitulatif-ia', with: [
            'lienActions' => BoiteDeTravail::getUrl(),
            'lienDecisions' => DecisionIaResource::getUrl('index'),
        ]);
    }
}
