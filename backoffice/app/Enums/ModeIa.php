<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Mode de l'assistant IA pour une file de la boîte de travail (plan-module-ia) :
 * désactivé (comportement d'avant le module), observation (il propose, ne modifie rien), actif (il applique ce dont il est sûr).
 */
enum ModeIa: string implements HasLabel
{
    case Desactive = 'desactive';
    case Observation = 'observation';
    case Actif = 'actif';

    public function getLabel(): string
    {
        return match ($this) {
            self::Desactive => 'Désactivé',
            self::Observation => 'Observation (propose seulement)',
            self::Actif => 'Actif (applique ce dont il est sûr)',
        };
    }
}
