<?php

namespace App\Collecte;

use App\Support\Point;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Géocodage d'une adresse par la Base Adresse Nationale (service gratuit de la Géoplateforme, F7.5).
 * Une panne du service ne bloque jamais la collecte : on renvoie simplement « pas trouvé ».
 */
class BaseAdresseNationale
{
    /** Types de résultat assez précis pour placer un lieu (pas une simple commune). */
    private const TYPES_PRECIS = ['housenumber', 'street', 'locality'];

    /** @return array{position: Point, code_insee: ?string}|null */
    public function geocoder(string $adresse, ?string $codePostal = null, ?string $ville = null): ?array
    {
        $recherche = trim(implode(' ', array_filter([$adresse, $codePostal === null ? $ville : null])));

        if (mb_strlen($recherche) < 3) {
            return null;
        }

        try {
            $resultat = Http::timeout(10)->retry(2, 500, throw: false)
                ->get(config('collecte.geocodage_url'), array_filter([
                    'q' => mb_substr($recherche, 0, 200),
                    'postcode' => $codePostal,
                    'limit' => 1,
                ]))
                ->json('features.0');
        } catch (Throwable) {
            return null;
        }

        $proprietes = $resultat['properties'] ?? [];
        $coordonnees = $resultat['geometry']['coordinates'] ?? null;

        if (! is_array($coordonnees) || ($proprietes['score'] ?? 0) < config('collecte.geocodage_score_minimal')
            || ! in_array($proprietes['type'] ?? '', self::TYPES_PRECIS, true)) {
            return null;
        }

        return [
            'position' => new Point((float) $coordonnees[1], (float) $coordonnees[0]),
            'code_insee' => $proprietes['citycode'] ?? null,
        ];
    }
}
