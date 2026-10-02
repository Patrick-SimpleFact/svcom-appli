<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TypeLieu: string implements HasLabel
{
    case Theatre = 'theatre';
    case Scene = 'scene';
    case SalleConcert = 'salle_concert';
    case Opera = 'opera';
    case Cirque = 'cirque';
    case CentreCreation = 'centre_creation';
    case CentreCulturel = 'centre_culturel';
    case PleinAir = 'plein_air';
    case Autre = 'autre';

    public function getLabel(): string
    {
        return match ($this) {
            self::Theatre => 'Théâtre',
            self::Scene => 'Scène / salle de spectacle',
            self::SalleConcert => 'Salle de concert',
            self::Opera => 'Opéra',
            self::Cirque => 'Cirque',
            self::CentreCreation => 'Centre de création',
            self::CentreCulturel => 'Centre culturel',
            self::PleinAir => 'Plein air',
            self::Autre => 'Autre',
        };
    }
}
