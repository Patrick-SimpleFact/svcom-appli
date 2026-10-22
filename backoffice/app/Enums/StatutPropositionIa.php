<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Devenir d'une proposition de l'assistant IA. */
enum StatutPropositionIa: string implements HasColor, HasLabel
{
    case Proposee = 'proposee';
    case Appliquee = 'appliquee';
    case Refusee = 'refusee';
    case Annulee = 'annulee';

    public function getLabel(): string
    {
        return match ($this) {
            self::Proposee => 'Proposée',
            self::Appliquee => 'Appliquée',
            self::Refusee => 'Refusée',
            self::Annulee => 'Annulée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Proposee => 'info',
            self::Appliquee => 'success',
            self::Refusee => 'gray',
            self::Annulee => 'warning',
        };
    }
}
