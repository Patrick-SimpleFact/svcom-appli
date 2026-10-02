<?php

namespace App\Models\Concerns;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Model;

/**
 * Un champ corrigé à la main par un admin est « verrouillé » :
 * les imports et la collecte ne l'écrasent plus (F7.8).
 */
trait VerrouilleCorrections
{
    /** Champs qui ne se verrouillent pas (techniques ou d'administration). */
    protected function champsNonVerrouillables(): array
    {
        return ['champs_verrouilles', 'masque', 'fusionne_dans_id', 'nom_normalise', 'created_at', 'updated_at'];
    }

    public static function bootVerrouilleCorrections(): void
    {
        static::updating(function (Model $modele) {
            if (! auth()->user() instanceof Admin) {
                return;
            }

            $corriges = array_diff(array_keys($modele->getDirty()), $modele->champsNonVerrouillables());

            if ($corriges !== []) {
                $modele->champs_verrouilles = array_values(array_unique([...($modele->champs_verrouilles ?? []), ...$corriges]));
            }
        });
    }

    public function estVerrouille(string $champ): bool
    {
        return in_array($champ, $this->champs_verrouilles ?? [], true);
    }
}
