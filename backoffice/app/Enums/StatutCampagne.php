<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Statut d'une campagne sponsorisée (F6.4) : seule une campagne « active » dans sa période est montrée. */
enum StatutCampagne: string implements HasColor, HasLabel
{
    case Brouillon = 'brouillon';
    case Active = 'active';
    case Suspendue = 'suspendue';
    case Terminee = 'terminee';

    public function getLabel(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Active => 'Active',
            self::Suspendue => 'Suspendue',
            self::Terminee => 'Terminée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Brouillon => 'gray',
            self::Active => 'success',
            self::Suspendue => 'warning',
            self::Terminee => 'info',
        };
    }
}
