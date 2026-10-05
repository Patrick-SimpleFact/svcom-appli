<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StatutRepresentation: string implements HasColor, HasLabel
{
    case Programmee = 'programmee';
    case Annulee = 'annulee';
    case Masquee = 'masquee';

    /** Plus aucune billetterie ne la vend (COLLECTE §8.2) ; elle revient si une offre réapparaît. */
    case Retiree = 'retiree';

    public function getLabel(): string
    {
        return match ($this) {
            self::Programmee => 'Programmée',
            self::Annulee => 'Annulée',
            self::Masquee => 'Masquée',
            self::Retiree => 'Retirée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Programmee => 'success',
            self::Annulee => 'danger',
            self::Masquee => 'gray',
            self::Retiree => 'warning',
        };
    }
}
