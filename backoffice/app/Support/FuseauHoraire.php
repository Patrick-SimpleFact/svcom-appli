<?php

namespace App\Support;

/**
 * Fuseau horaire d'une commune selon son département ou sa collectivité (F2.2, outre-mer).
 */
class FuseauHoraire
{
    private const PAR_DEPARTEMENT = [
        '971' => 'America/Guadeloupe',
        '972' => 'America/Martinique',
        '973' => 'America/Cayenne',
        '974' => 'Indian/Reunion',
        '975' => 'America/Miquelon',
        '976' => 'Indian/Mayotte',
        '977' => 'America/St_Barthelemy',
        '978' => 'America/Marigot',
        '984' => 'Indian/Kerguelen',
        '986' => 'Pacific/Wallis',
        '987' => 'Pacific/Tahiti',
        '988' => 'Pacific/Noumea',
        '989' => 'Pacific/Tahiti', // Clipperton (inhabitée)
    ];

    public static function pourDepartement(?string $departement): string
    {
        return self::PAR_DEPARTEMENT[$departement] ?? 'Europe/Paris';
    }
}
