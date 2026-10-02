<?php

namespace App\Filament\Resources\Lieux\Pages\Concerns;

use App\Support\Point;

/**
 * Le formulaire manipule latitude / longitude ; la base stocke une position PostGIS.
 */
trait ConvertitPosition
{
    protected function positionVersFormulaire(array $donnees): array
    {
        $point = $this->record?->position;
        unset($donnees['position']); // objet non transmissible au navigateur

        return [...$donnees, 'latitude' => $point?->latitude, 'longitude' => $point?->longitude];
    }

    protected function formulaireVersPosition(array $donnees): array
    {
        $latitude = $donnees['latitude'] ?? null;
        $longitude = $donnees['longitude'] ?? null;
        unset($donnees['latitude'], $donnees['longitude']);

        $donnees['position'] = is_numeric($latitude) && is_numeric($longitude)
            ? new Point((float) $latitude, (float) $longitude)
            : null;

        return $donnees;
    }
}
