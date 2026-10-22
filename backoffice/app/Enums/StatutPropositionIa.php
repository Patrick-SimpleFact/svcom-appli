<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Devenir d'une proposition de l'assistant IA. En cascade (v2), chaque modèle donne un « avis » ;
 * la cascade en tire une décision (proposée, puis appliquée…) ou laisse l'élément à Patrick (« à décider »).
 */
enum StatutPropositionIa: string implements HasColor, HasLabel
{
    case Proposee = 'proposee';
    case Appliquee = 'appliquee';
    case Refusee = 'refusee';
    case Annulee = 'annulee';
    case Echec = 'echec'; // réponse absente ou hors cadre : l'élément n'est pas redemandé avant un moment
    case Avis = 'avis'; // réponse d'un modèle de la cascade, jamais appliquée telle quelle
    case Doute = 'doute'; // la cascade n'a pas tranché : à décider par Patrick (« Mes actions du jour »)

    public function getLabel(): string
    {
        return match ($this) {
            self::Proposee => 'Proposée',
            self::Appliquee => 'Appliquée',
            self::Refusee => 'Refusée',
            self::Annulee => 'Annulée',
            self::Echec => 'Sans réponse',
            self::Avis => 'Avis d’un modèle',
            self::Doute => 'À décider',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Proposee => 'info',
            self::Appliquee => 'success',
            self::Refusee => 'gray',
            self::Annulee => 'warning',
            self::Echec => 'danger',
            self::Avis => 'gray',
            self::Doute => 'warning',
        };
    }
}
