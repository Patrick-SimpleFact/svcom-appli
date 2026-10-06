<?php

namespace App\Collecte;

use App\Support\Point;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Base Adresse Nationale (service gratuit de la Géoplateforme, ex-api-adresse.data.gouv.fr, F7.5).
 * - pendant la collecte : le meilleur résultat, s'il est assez sûr (`geocoder`) ;
 * - dans le back-office : les meilleurs résultats, parmi lesquels l'admin choisit (`rechercher`, K04b).
 * Une panne du service ne bloque jamais rien : on renvoie simplement « pas trouvé ».
 */
class BaseAdresseNationale
{
    /** Types de résultat assez précis pour placer un lieu (pas une simple commune). */
    private const TYPES_PRECIS = ['housenumber', 'street', 'locality'];

    /** @return array{position: Point, code_insee: ?string}|null */
    public function geocoder(string $adresse, ?string $codePostal = null, ?string $ville = null, ?string $codeInsee = null): ?array
    {
        $recherche = trim(implode(' ', array_filter([$adresse, $codePostal === null && $codeInsee === null ? $ville : null])));
        $resultat = $this->rechercher($recherche, $codePostal, $codeInsee, 1)[0] ?? null;

        if ($resultat === null || $resultat['score'] < config('collecte.geocodage_score_minimal') || ! in_array($resultat['type'], self::TYPES_PRECIS, true)) {
            return null;
        }

        return ['position' => $resultat['position'], 'code_insee' => $resultat['code_insee']];
    }

    /**
     * Recherche stricte (sans autocomplétion), filtrée par code postal et code INSEE si on les connaît.
     *
     * @return list<array{libelle: string, adresse: string, code_postal: ?string, commune: ?string, code_insee: ?string, type: string, score: float, position: Point}>
     */
    public function rechercher(string $texte, ?string $codePostal = null, ?string $codeInsee = null, int $nombre = 5): array
    {
        if (mb_strlen(trim($texte)) < 3) {
            return [];
        }

        try {
            $resultats = Http::timeout(10)->retry(2, 500, throw: false)
                ->get(config('collecte.geocodage_url'), array_filter([
                    'q' => mb_substr(trim($texte), 0, 200),
                    'postcode' => $codePostal,
                    'citycode' => $codeInsee,
                    'autocomplete' => 0,
                    'limit' => $nombre,
                ], fn ($v) => $v !== null && $v !== ''))
                ->json('features', []);
        } catch (Throwable) {
            return [];
        }

        return collect($resultats)
            ->filter(fn ($r) => is_array($r['geometry']['coordinates'] ?? null))
            ->map(fn (array $r) => [
                'libelle' => $r['properties']['label'] ?? '',
                'adresse' => $r['properties']['name'] ?? '',
                'code_postal' => $r['properties']['postcode'] ?? null,
                'commune' => $r['properties']['city'] ?? null,
                'code_insee' => $r['properties']['citycode'] ?? null,
                'type' => $r['properties']['type'] ?? '',
                'score' => (float) ($r['properties']['score'] ?? 0),
                'position' => new Point((float) $r['geometry']['coordinates'][1], (float) $r['geometry']['coordinates'][0]),
            ])
            ->values()
            ->all();
    }
}
