<?php

namespace App\Console\Commands;

use App\Comptes\Comptes;
use App\Models\Utilisateur;
use Illuminate\Console\Command;

/**
 * F1.7 : suppression demandée par e-mail, sans l'application (page /suppression-compte, exigée par Google Play).
 * Même effet que « Supprimer mon compte » dans l'app ; la ligne vide est purgée 30 jours plus tard (comptes:purger).
 */
class SupprimerCompteCommande extends Command
{
    protected $signature = 'comptes:supprimer {email : adresse e-mail du compte}';

    protected $description = 'Supprime le compte d’une personne qui l’a demandé par e-mail';

    public function handle(Comptes $comptes): int
    {
        $email = mb_strtolower(trim($this->argument('email')));
        $u = Utilisateur::whereNull('supprime_le')->whereRaw('lower(email) = ?', [$email])->first();

        if (! $u) {
            $this->error("Aucun compte actif avec l’adresse {$email}.");

            return self::FAILURE;
        }

        if (! $this->confirm("Supprimer le compte n° {$u->id} ({$email}, créé le {$u->created_at?->format('d/m/Y')}) ?")) {
            $this->info('Rien n’a été supprimé.');

            return self::SUCCESS;
        }

        $comptes->supprimer($u);
        $this->info("Compte n° {$u->id} supprimé : accès coupé et données personnelles effacées. Pensez à le confirmer par e-mail à {$email}.");

        return self::SUCCESS;
    }
}
