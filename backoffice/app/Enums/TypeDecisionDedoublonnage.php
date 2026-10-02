<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TypeDecisionDedoublonnage: string implements HasLabel
{
    case Fusionner = 'fusionner';
    case Separer = 'separer';

    public function getLabel(): string
    {
        return match ($this) {
            self::Fusionner => 'Fusionner',
            self::Separer => 'Séparer',
        };
    }
}
