<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Fiabilité de la position d'un lieu (F5.7) : « commune » = position approximative.
 */
enum PrecisionPosition: string implements HasColor, HasLabel
{
    case Exacte = 'exacte';
    case Adresse = 'adresse';
    case Commune = 'commune';

    public function getLabel(): string
    {
        return match ($this) {
            self::Exacte => 'Exacte',
            self::Adresse => 'Calculée depuis l’adresse',
            self::Commune => 'Approximative (centre de la commune)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Exacte => 'success',
            self::Adresse => 'info',
            self::Commune => 'warning',
        };
    }
}
