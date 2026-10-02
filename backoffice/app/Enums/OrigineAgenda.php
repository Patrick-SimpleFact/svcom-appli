<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OrigineAgenda: string implements HasLabel
{
    case Recherche = 'recherche';
    case Piste = 'piste';
    case Manuelle = 'manuelle';

    public function getLabel(): string
    {
        return match ($this) {
            self::Recherche => 'Recherche automatique',
            self::Piste => 'Piste utilisateur',
            self::Manuelle => 'Ajout manuel',
        };
    }
}
