<?php

namespace App\Actions;

use App\Enums\ActionJournal;
use App\Models\Admin;
use App\Models\JournalAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Trace une action manuelle d'un admin : qui, quoi, sur quoi, avant / après (F7.1).
 * Ne fait rien hors d'une session admin (collecte automatique, console…).
 */
class EnregistrerActionJournal
{
    /** Champs ignorés : techniques, sans intérêt pour le journal. */
    private const CHAMPS_IGNORES = [
        'remember_token',
        'created_at',
        'updated_at',
    ];

    /** Champs sensibles : le changement est noté, jamais la valeur. */
    private const CHAMPS_SENSIBLES = [
        'password' => 'mot de passe',
        'app_authentication_secret' => 'double authentification',
        'app_authentication_recovery_codes' => 'codes de secours',
    ];

    private const MASQUE = '••• (masqué)';

    public function handle(ActionJournal $action, Model $cible, ?array $avant, ?array $apres): ?JournalAction
    {
        $admin = auth()->user();

        if (! $admin instanceof Admin) {
            return null;
        }

        $avant = $this->nettoyer($avant);
        $apres = $this->nettoyer($apres);

        if ($action === ActionJournal::Modification && $apres === []) {
            return null;
        }

        return JournalAction::create([
            'admin_id' => $admin->id,
            'action' => $action,
            'cible_type' => $cible->getMorphClass(),
            'cible_id' => $cible->getKey(),
            'avant' => $avant,
            'apres' => $apres,
        ]);
    }

    /** Retire les champs techniques et masque les valeurs sensibles. */
    private function nettoyer(?array $valeurs): ?array
    {
        if ($valeurs === null) {
            return null;
        }

        $valeurs = Arr::except($valeurs, self::CHAMPS_IGNORES);

        foreach (self::CHAMPS_SENSIBLES as $champ => $libelle) {
            if (array_key_exists($champ, $valeurs)) {
                unset($valeurs[$champ]);
                $valeurs[$libelle] = self::MASQUE;
            }
        }

        return $valeurs;
    }
}
