<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Suite donnée à un signalement (F5.6) : jamais de masquage automatique, c'est le super-admin qui décide. */
enum ActionSignalement: string implements HasLabel
{
    case Corrige = 'corrige';
    case Masque = 'masque';
    case Rien = 'rien';

    public function getLabel(): string
    {
        return match ($this) {
            self::Corrige => 'Corrigé',
            self::Masque => 'Séance masquée',
            self::Rien => 'Aucune',
        };
    }
}
