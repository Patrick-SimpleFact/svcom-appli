<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum RoleArtiste: string implements HasLabel
{
    case Interprete = 'interprete';
    case MiseEnScene = 'mise_en_scene';
    case Auteur = 'auteur';
    case Compagnie = 'compagnie';
    case Autre = 'autre';

    public function getLabel(): string
    {
        return match ($this) {
            self::Interprete => 'Interprète',
            self::MiseEnScene => 'Mise en scène',
            self::Auteur => 'Auteur',
            self::Compagnie => 'Compagnie',
            self::Autre => 'Autre',
        };
    }
}
