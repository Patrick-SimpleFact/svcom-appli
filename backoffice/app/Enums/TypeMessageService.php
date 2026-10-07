<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Type d'un message de service (F2.8) : information ou alerte (incident). */
enum TypeMessageService: string implements HasColor, HasLabel
{
    case Info = 'info';
    case Alerte = 'alerte';

    public function getLabel(): string
    {
        return match ($this) {
            self::Info => 'Information',
            self::Alerte => 'Alerte',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Info => 'info',
            self::Alerte => 'danger',
        };
    }
}
