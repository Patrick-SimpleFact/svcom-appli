<?php

namespace App\Support;

use App\Models\Parametre;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Horizon des séances gérées en base (réglage « horizon_mois », demande de Patrick du 06/10/2026) : dernier jour du mois
 * situé N mois après aujourd'hui (6 le 06/10/2026 → 30/04/2027). Null = sans limite.
 */
class Horizon
{
    public static function dateLimite(): ?CarbonImmutable
    {
        try {
            $mois = (int) Parametre::valeur('horizon_mois');
        } catch (Throwable) {
            return null; // réglage absent : sans limite
        }

        return $mois > 0 ? CarbonImmutable::today('Europe/Paris')->addMonthsNoOverflow($mois)->endOfMonth() : null;
    }
}
