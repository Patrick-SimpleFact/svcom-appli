<?php

namespace App\Collecte;

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

    /** Titre ramené à une forme comparable : sans le nom du lieu ajouté en sous-titre, sans accents ni mots vides. */
    public function titreComparable(string $titre, ?string $lieu = null, ?string $ville = null): string
    {
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
