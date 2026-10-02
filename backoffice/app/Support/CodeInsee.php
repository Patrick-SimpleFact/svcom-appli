<?php

namespace App\Support;

/**
 * Paris, Lyon et Marseille ont des codes INSEE d'arrondissement (75101…, 69381…, 13201…)
 * en plus du code de la commune : on ramène toujours un arrondissement à sa commune.
 */
class CodeInsee
{
    public static function commune(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return match (true) {
            preg_match('/^751(0[1-9]|1\d|20)$/', $code) === 1 => '75056', // Paris
            preg_match('/^6938[1-9]$/', $code) === 1 => '69123', // Lyon
            preg_match('/^132(0[1-9]|1[0-6])$/', $code) === 1 => '13055', // Marseille
            default => $code,
        };
    }
}
