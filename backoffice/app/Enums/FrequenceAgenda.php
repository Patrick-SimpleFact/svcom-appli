<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FrequenceAgenda: string implements HasLabel
{
    case Normale = 'normale';
    case Hebdomadaire = 'hebdomadaire';

    public function getLabel(): string
    {
        return match ($this) {
            self::Normale => 'Normale',
            self::Hebdomadaire => 'Hebdomadaire (agenda peu actif)',
        };
    }
}
