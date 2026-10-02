<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TypeAccesSource: string implements HasLabel
{
    case Awin = 'awin';
    case Api = 'api';
    case OpenData = 'open_data';
    case SaisieSalle = 'saisie_salle';

    public function getLabel(): string
    {
        return match ($this) {
            self::Awin => 'Flux d\'affiliation Awin',
            self::Api => 'API',
            self::OpenData => 'Données ouvertes',
            self::SaisieSalle => 'Saisie par le lieu',
        };
    }
}
