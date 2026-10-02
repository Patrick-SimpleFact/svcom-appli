<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StatutRepresentation: string implements HasColor, HasLabel
{
    case Programmee = 'programmee';
    case Annulee = 'annulee';
    case Masquee = 'masquee';

    public function getLabel(): string
    {
        return match ($this) {
            self::Programmee => 'Programmée',
            self::Annulee => 'Annulée',
            self::Masquee => 'Masquée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Programmee => 'success',
            self::Annulee => 'danger',
            self::Masquee => 'gray',
        };
    }
}
