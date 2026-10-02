<?php

namespace App\Collecte;

use App\Models\Source;

/**
 * Connecteur dont la source publie une « version » (date de dernière mise à jour, nom de fichier, ETag…).
 * Le détecteur la consulte toutes les 30 min et ne lance la collecte que si elle a changé (COLLECTE §1).
 */
interface DetecteVersion
{
    public function versionDisponible(Source $source): ?string;
}
