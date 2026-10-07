<?php

namespace App\Mail;

use App\Filament\Resources\Sources\SourceResource;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/**
 * E-mail au super-admin : alertes de supervision nouvelles et résolues (F7.9).
 */
class AlertesSupervision extends Mailable
{
    use Queueable;

    public function __construct(
        public Collection $ouvertes,
        public Collection $resolues,
    ) {}

    public function envelope(): Envelope
    {
        $sujet = match (true) {
            $this->ouvertes->isNotEmpty() => '⚠️ Spettacoli : '.$this->ouvertes->count().' alerte(s) — '
                .$this->ouvertes->map(fn ($a) => $a->source->nom)->unique()->implode(', '),
            default => '✅ Spettacoli : '.$this->resolues->count().' alerte(s) résolue(s)',
        };

        return new Envelope(subject: $sujet);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.alertes-supervision', with: ['lienSources' => SourceResource::getUrl('index')]);
    }
}
