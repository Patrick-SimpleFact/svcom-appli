<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StatutElement: string implements HasLabel
{
    case EnAttente = 'en_attente';
    case Traite = 'traite';

    public function getLabel(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::Traite => 'Traité',
        };
    }
}
