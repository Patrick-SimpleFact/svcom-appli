<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * F2.2 : séance à heure connue, journée sans horaire (« horaire à confirmer »), ou spectacle en continu.
 */
enum TypeRepresentation: string implements HasLabel
{
    case Seance = 'seance';
    case Jour = 'jour';
    case Continu = 'continu';

    public function getLabel(): string
    {
        return match ($this) {
            self::Seance => 'Séance',
            self::Jour => 'Journée (horaire à confirmer)',
            self::Continu => 'En continu',
        };
    }
}
