<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Choix rapides de « Signaler une erreur » (F5.6). */
enum MotifSignalement: string implements HasLabel
{
    case HoraireFaux = 'horaire_faux';
    case Annule = 'annule';
    case MauvaisLieu = 'mauvais_lieu';
    case Doublon = 'doublon';
    case Autre = 'autre';

    public function getLabel(): string
    {
        return match ($this) {
            self::HoraireFaux => 'Horaire faux',
            self::Annule => 'Spectacle annulé',
            self::MauvaisLieu => 'Mauvais lieu',
            self::Doublon => 'Doublon',
            self::Autre => 'Autre',
        };
    }
}
