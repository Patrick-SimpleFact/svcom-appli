<?php

namespace App\Collecte;

use App\Support\Texte;

/**
 * Nom d'un lieu à partir des lignes d'adresse d'une source (DATAtourisme les range dans l'ordre de saisie :
 * « 41 rue Jobin », « Cabaret Aléatoire », « Friche la Belle de Mai »), et repérage des noms qui ne disent pas
 * où aller (« 62a Grand-Rue », « Hunspach », « Salle polyvalente ») — L01f.
 */
class NomDeLieu
{
    /** Début d'une ligne d'adresse : numéro, type de voie, boîte postale, lieu-dit. Comparé au texte normalisé. */
    private const VOIE = '/^(\d|(rue|r|place|pl|avenue|av|ave|bd|bld|boulevard|chemin|ch|route|rte|impasse|imp|allee|allees|quai|cours|crs|square|sq|esplanade|chaussee|passage|parvis|rond point|lieu dit|lieudit|zac|za|zi|bp|cs|cedex|faubourg|fg|fbg|promenade|sentier|hameau|residence)\b)/';

    /** Mot qui désigne une salle ou un établissement : la ligne qui le porte est le nom du lieu. */
    private const SALLE = '/\b(salle|mediatheque|bibliotheque|theatre|cabaret|espace|maison|centre|eglise|chapelle|cathedrale|abbaye|basilique|temple|cinema|palais|cafe|bar|auditorium|amphitheatre|arene|arenes|scene|foyer|halle|halles|chateau|musee|conservatoire|zenith|opera|forum|grange|gymnase|mairie|friche|galerie|kiosque|cloitre|studio|atelier|club|complexe|domaine|auberge|restaurant|pub|parc des expositions)\b/';

    /** Mots qui, seuls, ne permettent pas de trouver la salle (« Salle des fêtes », « Foyer rural », « Hors les murs »). */
    private const MOTS_VAGUES = [
        'salle', 'salles', 'polyvalente', 'polyvalent', 'fetes', 'fete', 'municipal', 'municipale', 'communale', 'communal',
        'socio', 'culturel', 'culturelle', 'foyer', 'rural', 'mairie', 'mediatheque', 'bibliotheque', 'eglise', 'chapelle',
        'theatre', 'espace', 'centre', 'kiosque', 'halle', 'gymnase', 'cinema', 'scene', 'auditorium', 'temple',
        'hors', 'murs', 'lieu', 'lieux', 'venir', 'preciser', 'plein', 'air', 'exterieur', 'parc', 'jardin',
        'le', 'la', 'les', 'l', 'de', 'du', 'des', 'd', 'a', 'au', 'aux', 'en', 'et',
    ];

    /**
     * Sépare le nom de la salle des lignes d'adresse.
     *
     * @param  list<string>  $lignes
     * @return array{0: ?string, 1: list<string>} nom (ou null) et lignes restantes de l'adresse
     */
    public static function separer(array $lignes): array
    {
        $lignes = array_values(array_filter(array_map('trim', $lignes), fn (string $l) => $l !== ''));

        if ($lignes === []) {
            return [null, []];
        }

        // Le nom en tête : « TMP - Théâtre Municipal Pazenais », puis « 7 rue du Ballon » ; ou une seule ligne sans numéro
        // (« Cinéma Le Doron »). Règle d'avant L01f, gardée telle quelle : elle fait la clé des lieux déjà vus.
        if (count($lignes) >= 2 ? ! preg_match('/^\d/', $lignes[0]) : ! preg_match('/\d/', $lignes[0])) {
            return [array_shift($lignes), $lignes];
        }

        // Le nom après l'adresse : la première ligne qui nomme une salle.
        foreach ($lignes as $i => $ligne) {
            if ($i > 0 && ! self::estVoie($ligne) && preg_match(self::SALLE, Texte::normaliser($ligne))) {
                array_splice($lignes, $i, 1);

                return [$ligne, $lignes];
            }
        }

        return [null, $lignes];
    }

    /** Le nom ne permet pas de trouver la salle : c'est l'adresse, la commune, ou un nom générique. */
    public static function estImprecis(?string $nom, ?string $adresse = null, ?string $commune = null): bool
    {
        $nom = Texte::normaliser($nom);
        $adresse = Texte::normaliser($adresse);

        // « Square Carpeaux » (23 rue Carpeaux) ou « 7-9 Comedy Room » sont des noms ; « Rue de Torigni » sans adresse, non.
        return $nom === ''
            || preg_match('/^\d+[a-z]?$/', $nom)
            || (self::estVoie($nom) && ($adresse === '' || $adresse === $nom))
            || $nom === $adresse
            || $nom === Texte::normaliser($commune)
            || array_diff(explode(' ', $nom), self::MOTS_VAGUES) === [];
    }

    private static function estVoie(string $ligne): bool
    {
        return (bool) preg_match(self::VOIE, Texte::normaliser($ligne));
    }
}
