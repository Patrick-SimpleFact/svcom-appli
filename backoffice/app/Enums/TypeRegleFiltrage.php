<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TypeRegleFiltrage: string implements HasLabel
{
    case Exclure = 'exclure';
    case Inclure = 'inclure';

    public function getLabel(): string
    {
        return match ($this) {
            self::Exclure => 'Exclure',
            self::Inclure => 'Inclure',
        };
    }
}
