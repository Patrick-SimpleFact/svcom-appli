<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TypeLienSource: string implements HasLabel
{
    case Affilie = 'affilie';
    case Direct = 'direct';
    case Aucun = 'aucun';

    public function getLabel(): string
    {
        return match ($this) {
            self::Affilie => 'Lien affilié',
            self::Direct => 'Lien direct',
            self::Aucun => 'Aucun lien de réservation',
        };
    }
}
