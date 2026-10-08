<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Où en est une demande d'espace salle (F9.1, F9.2). */
enum StatutDemandeSalle: string implements HasColor, HasLabel
{
    case EnAttente = 'en_attente';
    case PrecisionsDemandees = 'precisions_demandees';
    case Validee = 'validee';
    case Refusee = 'refusee';

    public const OUVERTS = [self::EnAttente, self::PrecisionsDemandees];

    public function getLabel(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::PrecisionsDemandees => 'Précisions demandées',
            self::Validee => 'Validée',
            self::Refusee => 'Refusée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::EnAttente => 'warning',
            self::PrecisionsDemandees => 'info',
            self::Validee => 'success',
            self::Refusee => 'gray',
        };
    }
}
