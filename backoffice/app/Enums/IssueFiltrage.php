<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Les trois issues du filtre « spectacle vivant » (COLLECTE §5). */
enum IssueFiltrage: string implements HasLabel
{
    case Garde = 'garde';
    case Exclu = 'exclu';
    case ATrier = 'a_trier';

    public function getLabel(): string
    {
        return match ($this) {
            self::Garde => 'Gardé',
            self::Exclu => 'Exclu',
            self::ATrier => 'À trier',
        };
    }
}
