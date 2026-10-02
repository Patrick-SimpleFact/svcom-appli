<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ActionJournal: string implements HasColor, HasLabel
{
    case Creation = 'creation';
    case Modification = 'modification';
    case Suppression = 'suppression';

    public function getLabel(): string
    {
        return match ($this) {
            self::Creation => 'Création',
            self::Modification => 'Modification',
            self::Suppression => 'Suppression',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Creation => 'success',
            self::Modification => 'warning',
            self::Suppression => 'danger',
        };
    }
}
