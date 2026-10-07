<?php

namespace App\Api;

use App\Support\Texte;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * Texte tapé dans la barre de recherche (F4.1) : sans accents ni majuscules, articles retirés
 * (« le Chêne Noir » cherche « chene noir »). Les fautes légères sont tolérées par PostgreSQL (pg_trgm, word_similarity).
 */
final class TexteCherche
{
    public const LONGUEUR_MIN = 2;

    private const ARTICLES = ['le', 'la', 'les', 'l', 'un', 'une', 'des', 'du', 'de', 'd', 'au', 'aux', 'et'];

    /** Le texte prêt à comparer, ou null s'il est trop court (moins de 2 caractères utiles). */
    public static function preparer(?string $texte): ?string
    {
        $mots = array_values(array_diff(explode(' ', Texte::normaliser($texte)), self::ARTICLES));
        $prepare = trim(implode(' ', $mots));

        // « Le » seul : on garde le texte tel quel plutôt que rien.
        $prepare = $prepare === '' ? Texte::normaliser($texte) : $prepare;

        return mb_strlen($prepare) >= self::LONGUEUR_MIN ? $prepare : null;
    }

    /** Les mots qui comptent (3 lettres et plus) ; à défaut, le texte entier. */
    public static function mots(string $prepare): array
    {
        $mots = array_values(array_filter(explode(' ', $prepare), fn (string $m) => mb_strlen($m) >= 3));

        return $mots === [] ? [$prepare] : $mots;
    }

    /** Ressemblance minimale de chaque mot (plus souple que celle du texte entier, 0,6 : « chanse » doit trouver « chance »). */
    public const SEUIL_MOT = 0.45;

    /**
     * Le texte entier doit ressembler à un passage de la colonne (« mot proche » de pg_trgm, index par trigrammes),
     * et chacun de ses mots aussi : sans cette 2e règle, « laurete theatre » trouvait tous les théâtres.
     */
    public static function correspond(Builder|EloquentBuilder $requete, string $colonne, string $prepare): void
    {
        $requete->whereRaw("? <% {$colonne}", [$prepare]);

        foreach (self::mots($prepare) as $mot) {
            $requete->whereRaw("word_similarity(?, {$colonne}) >= ?", [$mot, self::SEUIL_MOT]);
        }
    }
}
