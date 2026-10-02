<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Normalisation de texte pour les rapprochements et la recherche
 * (même règle que le POC : minuscules, sans accents ni ponctuation).
 */
class Texte
{
    public static function normaliser(?string $texte): string
    {
        $texte = preg_replace('/[^\pL\pN\s]+/u', ' ', $texte ?? '');
        $texte = Str::lower(Str::ascii($texte));
        $texte = preg_replace('/[^a-z0-9 ]+/', ' ', $texte);

        return trim(preg_replace('/\s+/', ' ', $texte));
    }
}
