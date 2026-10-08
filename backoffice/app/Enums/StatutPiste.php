<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Cycle d'une piste (F8.5) : nouvelle → étudiée → intégrée ou écartée (les deux dernières envoient une réponse, F8.6). */
enum StatutPiste: string implements HasColor, HasLabel
{
    case Nouvelle = 'nouvelle';
    case Etudiee = 'etudiee';
    case Integree = 'integree';
    case Ecartee = 'ecartee';

    public const OUVERTS = [self::Nouvelle, self::Etudiee];

    public function getLabel(): string
    {
        return match ($this) {
            self::Nouvelle => 'Nouvelle',
            self::Etudiee => 'Étudiée',
            self::Integree => 'Intégrée',
            self::Ecartee => 'Écartée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Nouvelle => 'warning',
            self::Etudiee => 'info',
            self::Integree => 'success',
            self::Ecartee => 'gray',
        };
    }

    public function estOuvert(): bool
    {
        return in_array($this, self::OUVERTS, true);
    }
}
