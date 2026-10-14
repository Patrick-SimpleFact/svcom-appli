<?php

namespace App\Web;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Anti-robot des formulaires publics, sans service tiers : un champ piège caché, et un délai minimal entre
 * l'affichage et l'envoi (horodatage chiffré). Le visiteur reçoit la même réponse dans tous les cas ;
 * le journal garde la raison (jamais l'adresse saisie).
 *
 * Le champ piège porte un nom neutre (précaution) : un nom comme « site_entreprise » pourrait être pris
 * par la saisie automatique des navigateurs pour un champ « Société », et une vraie inscription serait ignorée.
 */
class AntiRobot
{
    public const CHAMP = 'zq_piege';

    public const SECONDES_MIN = 3;

    /** Raison du rejet (champ_piege, trop_rapide, horodatage_illisible), ou null si l'envoi semble humain. */
    public static function raison(Request $request, string $formulaire): ?string
    {
        $raison = match (true) {
            filled($request->input(self::CHAMP)) => 'champ_piege',
            default => self::delai($request->input('debut')),
        };
        if ($raison !== null) {
            Log::info('Formulaire ignoré (anti-robot)', ['formulaire' => $formulaire, 'raison' => $raison]);
        }

        return $raison;
    }

    private static function delai(?string $debut): ?string
    {
        try {
            return now()->timestamp - (int) Crypt::decrypt((string) $debut) < self::SECONDES_MIN ? 'trop_rapide' : null;
        } catch (DecryptException) {
            return 'horodatage_illisible';
        }
    }
}
