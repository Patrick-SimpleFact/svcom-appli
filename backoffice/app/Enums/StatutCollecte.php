<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StatutCollecte: string implements HasColor, HasLabel
{
    case EnCours = 'en_cours';
    case Reussie = 'reussie';
    case Echouee = 'echouee';
    case Abandonnee = 'abandonnee';

    public function getLabel(): string
    {
        return match ($this) {
            self::EnCours => 'En cours',
            self::Reussie => 'Réussie',
            self::Echouee => 'Échouée (nouvel essai prévu)',
            self::Abandonnee => 'Abandonnée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::EnCours => 'info',
            self::Reussie => 'success',
            self::Echouee => 'warning',
            self::Abandonnee => 'danger',
        };
    }
}
