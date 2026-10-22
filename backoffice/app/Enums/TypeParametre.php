<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TypeParametre: string implements HasLabel
{
    case Entier = 'entier';
    case Decimal = 'decimal';
    case Booleen = 'booleen';
    case Texte = 'texte';
    case ListeEntiers = 'liste_entiers';
    case ModeIa = 'mode_ia';

    public function getLabel(): string
    {
        return match ($this) {
            self::Entier => 'Nombre entier',
            self::Decimal => 'Nombre décimal',
            self::Booleen => 'Oui / non',
            self::Texte => 'Texte',
            self::ListeEntiers => 'Liste de nombres',
            self::ModeIa => 'Mode de l’assistant IA',
        };
    }
}
