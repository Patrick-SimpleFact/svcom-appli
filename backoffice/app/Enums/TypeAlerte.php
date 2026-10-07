<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Les alertes de supervision des sources (F7.9). */
enum TypeAlerte: string implements HasLabel
{
    /** Collecte abandonnée après ses 4 essais. */
    case Echec = 'echec';

    /** Aucune publication réussie depuis la veille à l'heure de contrôle (7 h). */
    case PublicationManquante = 'publication_manquante';

    /** Volume reçu nettement sous la moyenne des 7 derniers jours : flux probablement cassé. */
    case ChuteVolume = 'chute_volume';

    /** Dernière publication réussie trop ancienne : l'app sert des données périmées. */
    case DonneesPerimees = 'donnees_perimees';

    public function getLabel(): string
    {
        return match ($this) {
            self::Echec => 'Collecte en échec',
            self::PublicationManquante => 'Publication manquante',
            self::ChuteVolume => 'Chute du volume',
            self::DonneesPerimees => 'Données périmées',
        };
    }
}
