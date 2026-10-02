<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TypeArtiste: string implements HasLabel
{
    case Personne = 'personne';
    case Groupe = 'groupe';
    case Compagnie = 'compagnie';

    public function getLabel(): string
    {
        return match ($this) {
            self::Personne => 'Personne',
            self::Groupe => 'Groupe',
            self::Compagnie => 'Compagnie',
        };
    }
}
