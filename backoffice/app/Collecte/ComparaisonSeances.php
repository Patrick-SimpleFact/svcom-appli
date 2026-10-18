<?php

namespace App\Collecte;

use App\Actions\RattacherSpectacle;
use App\Support\Texte;

/**
 * Deux annonces sont-elles la même séance ? Règles calibrées sur le POC (COLLECTE §7.1, `poc/analyse.py`).
 * Chaque critère est « net », « limite » ou « faible » ; la décision en découle (§7.2).
 */
class ComparaisonSeances
{
    public const NET = 'net';

    public const LIMITE = 'limite';

    public const FAIBLE = 'faible';

    /** Marque provisoire d'une lettre masquée, qui traverse la normalisation sans être confondue avec un vrai mot. */
    private const MASQUE = 'zqxmasquezqx';

    private const MOTS_VIDES = ['le', 'la', 'les', 'l', 'un', 'une', 'des', 'de', 'du', 'd', 'et', 'a', 'au',
        // ajoutés par les billetteries autour du vrai titre (N02) : « Harold Barbé dans Relax Max », « Naïm - Chapitre 3 - Tournée »
        'dans', 'tournee'];

    /**
     * Sources dont les titres commencent souvent par leur catégorie (« Théâtre : … », « Humour “…” ») : les agendas
     * publics, remplis à la main. Mesuré en production le 17/10/2026 : DATAtourisme 23,6 %, OpenAgenda 12,3 %,
     * Que faire à Paris 2,8 % ; les billetteries presque jamais, et chez elles « Concert : » peut faire partie du titre.
     */
    public const SOURCES_CATEGORIE_EN_TETE = ['datatourisme', 'openagenda', 'paris_qfap'];

    /**
     * Mots de catégorie qu'on retire en tête du titre, avec les genres compatibles (null : pas de contrainte).
     * Le genre évite de réunir « Opéra - Carmen » et « Carmen - Ballet Julien Lestel ».
     */
    private const CATEGORIES = [
        'theatre' => ['theatre', 'humour'], 'piece' => ['theatre', 'humour'], 'comedie' => ['theatre', 'humour', 'comedie-musicale-cabaret'],
        'humour' => ['theatre', 'humour'], 'humoristique' => ['theatre', 'humour'], 'one' => ['theatre', 'humour'], 'stand' => ['theatre', 'humour'],
        'concert' => ['concert', 'opera-lyrique'], 'concerts' => ['concert', 'opera-lyrique'], 'chanson' => ['concert'], 'jazz' => ['concert'],
        'recital' => ['concert', 'opera-lyrique'], 'musique' => ['concert', 'comedie-musicale-cabaret', 'opera-lyrique'],
        'danse' => ['danse'], 'ballet' => ['danse'], 'opera' => ['opera-lyrique'], 'cirque' => ['cirque-magie'], 'magie' => ['cirque-magie'],
        'cabaret' => ['comedie-musicale-cabaret'], 'musical' => ['concert', 'comedie-musicale-cabaret'], 'musicale' => ['concert', 'comedie-musicale-cabaret'],
        'spectacle' => null, 'spectacles' => null, 'conte' => null, 'contes' => null, 'lecture' => null, 'marionnettes' => null,
        'jeune' => null, 'public' => null, 'man' => null, 'woman' => null, 'show' => null, 'up' => null, 'seul' => null, 'en' => null,
        'scene' => null, 'de' => null,
    ];

    /**
     * La catégorie annoncée en tête du titre, suivie d'un séparateur ou de guillemets : « Humour “Les c*ns, c'est les autres” »,
     * « Théâtre : Nénène », « Spectacle - Climax ». « Concert de Noël » n'en a pas (pas de séparateur), « Concert : Jazz » non plus
     * (il ne resterait qu'un titre générique).
     *
     * @return array{titre: string, genres: ?list<string>}|null le titre sans la catégorie et les genres compatibles
     */
    public function categorieEnTete(string $titre): ?array
    {
        if (! preg_match('/^(.{3,40}?)\s*(?:[:|–—]|\s-\s|(?=[«“"„]))\s*(.+)$/u', $titre, $m)) {
            return null;
        }

        $mots = array_values(array_filter(explode(' ', Texte::normaliser($m[1]))));

        if ($mots === [] || array_diff($mots, array_keys(self::CATEGORIES)) !== []) {
            return null;
        }

        $reste = preg_replace('/^[\s«»“”"„]+|[\s«»“”"„]+$/u', '', $m[2]);
        $comparable = $this->titreComparable($reste);

        if (mb_strlen($comparable) < 5 || RattacherSpectacle::estGenerique($comparable)) {
            return null;
        }

        // Plusieurs catégories (« Théâtre musical ») : les genres communs, sinon tous ceux qu'elles admettent.
        $genres = null;

        foreach ($mots as $mot) {
            if (self::CATEGORIES[$mot] !== null) {
                $genres = $genres === null ? self::CATEGORIES[$mot] : (array_values(array_intersect($genres, self::CATEGORIES[$mot])) ?: array_values(array_unique([...$genres, ...self::CATEGORIES[$mot]])));
            }
        }

        return ['titre' => $reste, 'genres' => $genres];
    }

    /**
     * Titre ramené à une forme comparable : sans le nom du lieu ajouté en sous-titre, sans accents ni mots vides,
     * et sans la catégorie en tête pour les sources qui l'ajoutent ($sansCategorie).
     */
    public function titreComparable(string $titre, ?string $lieu = null, ?string $ville = null, bool $sansCategorie = false): string
    {
        if ($sansCategorie && ($categorie = $this->categorieEnTete($titre)) !== null) {
            $titre = $categorie['titre'];
        }

        // « Mamouchka – Café Théâtre de la Porte d'Italie, Avignon » : le sous-titre qui nomme le lieu ou la ville est retiré.
        $morceaux = preg_split('/\s+[–—|-]\s+|\s*\|\s*/u', $titre);

        if (count($morceaux) > 1) {
            $dernier = Texte::normaliser(end($morceaux));
            $lieuNormalise = Texte::normaliser($lieu);
            $villeNormalisee = Texte::normaliser($ville);

            if (($lieuNormalise !== '' && (str_contains($dernier, $lieuNormalise) || str_contains($lieuNormalise, $dernier)))
                || ($villeNormalisee !== '' && str_contains($dernier, $villeNormalisee))) {
                array_pop($morceaux);
                $titre = implode(' - ', $morceaux);
            }
        }

        // Une lettre masquée (« c*ns ») est gardée sous la forme « * » pour la comparaison tolérante.
        $titre = preg_replace('/(?<=\pL)\*+|\*+(?=\pL)/u', self::MASQUE, $titre);
        $mots = array_filter(explode(' ', Texte::normaliser($titre)), fn (string $mot) => ! in_array($mot, self::MOTS_VIDES, true));

        return str_replace(self::MASQUE, '*', implode(' ', $mots));
    }

    /** Ressemblance de deux titres comparables, de 0 à 1 (même calcul que le POC). */
    public function ressemblanceTitres(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        // Titres identiques, même très courts (« Fred. ») : la règle « l'un contient l'autre » ci-dessous les refuserait.
        if ($a === $b) {
            return 1.0;
        }

        // Mot masqué : « les c*ns » correspond à « les cons ».
        foreach ([[$a, $b], [$b, $a]] as [$masque, $autre]) {
            if (str_contains($masque, '*') && preg_match('/^'.str_replace('\*', '[a-z]*', preg_quote($masque, '/')).'$/', $autre)) {
                return 1.0;
            }
        }

        // L'un contient l'autre : « edmond » / « edmond alexis michalik ».
        if (str_contains($a, $b) || str_contains($b, $a)) {
            return min(strlen($a), strlen($b)) >= 5 ? 0.9 : 0.0;
        }

        similar_text($a, $b, $pourcentage);

        return $pourcentage / 100;
    }

    public function niveauTitre(float $ressemblance): string
    {
        return match (true) {
            $ressemblance >= 0.8 => self::NET,
            $ressemblance >= 0.7 => self::LIMITE,
            default => self::FAIBLE,
        };
    }

    /** Écart d'heure en minutes (null si l'une des deux annonces n'a pas d'heure : critère non bloquant). */
    public function niveauHeure(?int $ecartMinutes, int $ecartMaxMinutes, int $ecartProbableMinutes = 60): string
    {
        return match (true) {
            $ecartMinutes === null, $ecartMinutes <= $ecartMaxMinutes => self::NET,
            $ecartMinutes <= $ecartProbableMinutes => self::LIMITE,
            default => self::FAIBLE,
        };
    }
}
