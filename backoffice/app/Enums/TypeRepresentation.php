<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * F2.2 : séance à heure connue, journée sans horaire (« horaire à confirmer »), spectacle en continu,
 * ou période sans horaire sur plusieurs jours (« du … au … », N03, décision de Patrick du 06/10/2026).
 */
enum TypeRepresentation: string implements HasLabel
{
    case Seance = 'seance';
    case Jour = 'jour';
    case Continu = 'continu';
    case Periode = 'periode';

    public function getLabel(): string
    {
        return match ($this) {
            self::Seance => 'Séance',
            self::Jour => 'Journée (horaire à confirmer)',
            self::Continu => 'En continu',
            self::Periode => 'Période (du … au …)',
        };
    }
}
