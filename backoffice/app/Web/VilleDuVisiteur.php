<?php

namespace App\Web;

use App\Http\Controllers\Api\AppareilController;
use App\Models\Ville;
use App\Support\Point;
use GeoIp2\Database\Reader;
use Illuminate\Http\Request;
use Throwable;

/**
 * Où est le visiteur de la page d'accueil (W05a) : sa position s'il l'a donnée (bouton « Me localiser »), sinon la ville
 * estimée par son adresse IP (base DB-IP chez nous, rien d'envoyé ailleurs), sinon Paris. Rien n'est enregistré (F2.11).
 */
class VilleDuVisiteur
{
    public const VILLE_PAR_DEFAUT = '75056'; // Paris

    public static function cheminBase(): string
    {
        return storage_path('app/geoip/dbip-city-lite.mmdb');
    }

    /** @return array{ville: Ville, centre: Point, origine: 'position'|'adresse_ip'|'defaut'}|null null si aucune commune n'est connue (base vide) */
    public function trouver(Request $requete): ?array
    {
        // La position arrive dans le corps d'une requête POST : elle n'apparaît ni dans l'adresse, ni dans les journaux du serveur.
        $lat = $requete->post('lat');
        $lon = $requete->post('lon');

        if (is_numeric($lat) && is_numeric($lon) && abs((float) $lat) <= 90 && abs((float) $lon) <= 180) {
            $centre = new Point((float) $lat, (float) $lon);
            if ($ville = Ville::find(AppareilController::communeProche($centre))) {
                return ['ville' => $ville, 'centre' => $centre, 'origine' => 'position'];
            }
        }

        if (($centre = $this->parAdresseIp((string) $requete->ip())) && ($ville = Ville::find(AppareilController::communeProche($centre)))) {
            return ['ville' => $ville, 'centre' => $ville->position, 'origine' => 'adresse_ip'];
        }

        $ville = Ville::firstWhere('code_insee', self::VILLE_PAR_DEFAUT) ?? Ville::where('est_pilote', true)->orderByDesc('population')->first();

        return $ville ? ['ville' => $ville, 'centre' => $ville->position, 'origine' => 'defaut'] : null;
    }

    /** Position approximative (ville) de l'adresse IP, seulement en France ; null si inconnue. */
    public function parAdresseIp(string $ip): ?Point
    {
        if (! is_readable(self::cheminBase()) || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        try {
            $lieu = (new Reader(self::cheminBase()))->city($ip);

            return $lieu->country->isoCode === 'FR' && $lieu->location->latitude !== null
                ? new Point($lieu->location->latitude, $lieu->location->longitude)
                : null;
        } catch (Throwable) {
            return null;
        }
    }
}
