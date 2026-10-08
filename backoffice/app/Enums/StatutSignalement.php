<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Où en est un signalement (SCHEMA §7) ; l'action prise est dans ActionSignalement. */
enum StatutSignalement: string implements HasColor, HasLabel
{
    case Nouveau = 'nouveau';
    case Traite = 'traite';
    case Rejete = 'rejete';

    public function getLabel(): string
    {
        return match ($this) {
            self::Nouveau => 'Nouveau',
            self::Traite => 'Traité',
            self::Rejete => 'Rejeté',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Nouveau => 'warning',
            self::Traite => 'success',
            self::Rejete => 'gray',
        };
    }
}
