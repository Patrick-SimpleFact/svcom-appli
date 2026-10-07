<?php

namespace App\Console\Commands;

use App\Models\Utilisateur;
use Illuminate\Console\Command;

/**
 * F1.7 : les comptes supprimés depuis plus de 30 jours disparaissent définitivement (déjà vidés de leurs données à la suppression).
 */
class PurgerComptesCommande extends Command
{
    public const JOURS = 30;

    protected $signature = 'comptes:purger';

    protected $description = 'Efface définitivement les comptes supprimés depuis plus de 30 jours';

    public function handle(): int
    {
        $nombre = Utilisateur::whereNotNull('supprime_le')->where('supprime_le', '<', now()->subDays(self::JOURS))->delete();
        $this->info("{$nombre} compte(s) effacé(s) définitivement.");

        return self::SUCCESS;
    }
}
