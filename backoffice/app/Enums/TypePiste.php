<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** « Que voulez-vous nous signaler ? » (F8.2). */
enum TypePiste: string implements HasLabel
{
    case Salle = 'salle';
    case Spectacle = 'spectacle';
    case BilletterieOuAgenda = 'billetterie_ou_agenda';

    public function getLabel(): string
    {
        return match ($this) {
            self::Salle => 'Salle ou lieu',
            self::Spectacle => 'Spectacle',
            self::BilletterieOuAgenda => 'Billetterie ou agenda',
        };
    }
}
