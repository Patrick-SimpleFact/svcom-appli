<?php

namespace App\Collecte;

use App\Support\Texte;

/**
 * Comparaison tolérante des noms et adresses de lieux (COLLECTE §4) :
 * sans accents, sans mots vides, abréviations d'adresse développées.
 */
class ComparaisonLieux
{
    private const MOTS_VIDES = ['le', 'la', 'les', 'l', 'de', 'du', 'des', 'd', 'et', 'a', 'au', 'aux', 'en', 'un', 'une'];

    /** Mots trop courants pour suffire seuls à reconnaître un lieu (« Théâtre » ≠ « Théâtre du Chêne noir »). */
    private const MOTS_GENERIQUES = [
        'theatre', 'salle', 'espace', 'centre', 'culturel', 'culturelle', 'scene', 'scenes', 'auditorium', 'cafe',
        'eglise', 'chapelle', 'cinema', 'opera', 'maison', 'petit', 'petite', 'grand', 'grande', 'nouveau', 'nouvelle',
        'municipal', 'municipale', 'ville', 'fetes', 'concert', 'concerts', 'spectacle', 'spectacles', 'cabaret', 'comedie',
    ];

    private const ABREVIATIONS = [
        'r' => 'rue', 'bd' => 'boulevard', 'bld' => 'boulevard', 'av' => 'avenue', 'ave' => 'avenue', 'pl' => 'place',
        'ch' => 'chemin', 'che' => 'chemin', 'imp' => 'impasse', 'all' => 'allee', 'crs' => 'cours', 'fg' => 'faubourg',
        'fbg' => 'faubourg', 'qu' => 'quai', 'rte' => 'route', 'sq' => 'square', 'st' => 'saint', 'ste' => 'sainte',
        'esc' => 'escaliers', 'pass' => 'passage', 'prom' => 'promenade',
    ];

    /** Deux noms désignent-ils (probablement) le même lieu ? */
    public function nomsProches(?string $a, ?string $b): bool
    {
        $motsA = $this->mots($a);
        $motsB = $this->mots($b);

        if ($motsA === [] || $motsB === []) {
            return false;
        }

        // L'un contient tous les mots de l'autre : « Chêne Noir » / « Théâtre du Chêne Noir ».
        [$court, $long] = count($motsA) <= count($motsB) ? [$motsA, $motsB] : [$motsB, $motsA];
        if (array_diff($court, $long) === [] && array_diff($court, self::MOTS_GENERIQUES) !== []) {
            return true;
        }

        // Sinon, orthographes voisines : « Théâtre des Halles » / « Theatre des Hales ».
        similar_text(implode(' ', $motsA), implode(' ', $motsB), $pourcentage);

        return $pourcentage >= 85;
    }

    /**
     * Salle précisée dans le nom de lieu donné par une source, si le lieu du catalogue ne la nomme pas déjà :
     * « Théâtre de l'Observance - salle 1 » → « Salle 1 » ; « Paradise République - Salle O » → « Salle O ».
     */
    public function salle(?string $nomSource, string $nomLieu): ?string
    {
        if (! preg_match('/\b(salle|studio)\b\s*([[:alnum:]’\'.-]+(?:\s+[[:alnum:]’\'.-]+)?)?/iu', (string) $nomSource, $m)) {
            return null;
        }

        $salle = trim(mb_convert_case(mb_substr($m[1], 0, 1), MB_CASE_UPPER).mb_substr($m[1], 1).' '.trim($m[2] ?? ''));

        return str_contains(Texte::normaliser($nomLieu), Texte::normaliser($salle)) ? null : mb_substr($salle, 0, 60);
    }

    /** Adresse ramenée à une forme comparable : « 4 r. Esc. Ste-Anne » = « 4 rue des Escaliers Sainte-Anne ». */
    public function adresseNormalisee(?string $adresse): string
    {
        $mots = array_map(fn (string $mot) => self::ABREVIATIONS[$mot] ?? $mot, explode(' ', Texte::normaliser($adresse)));

        return implode(' ', array_filter($mots, fn (string $mot) => $mot !== '' && ! in_array($mot, self::MOTS_VIDES, true)));
    }

    /** @return list<string> */
    private function mots(?string $nom): array
    {
        $mots = array_filter(explode(' ', Texte::normaliser($nom)), fn (string $mot) => $mot !== '' && ! in_array($mot, self::MOTS_VIDES, true));

        return array_values(array_unique($mots));
    }
}
