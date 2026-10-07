<?php

namespace App\Console\Commands;

use App\Actions\MesurerCouverture;
use Illuminate\Console\Command;

/**
 * Mesure la couverture des villes pilotes et l'ajoute à l'historique (F7.13).
 */
class MesurerCouvertureCommande extends Command
{
    protected $signature = 'couverture:mesurer';

    protected $description = 'Mesure la couverture des villes pilotes (ce soir, week-end, 30 jours) et l’enregistre pour le tableau de bord';

    public function handle(MesurerCouverture $mesurer): int
    {
        $this->info("{$mesurer->enregistrer()} ville(s) pilote(s) mesurée(s).");

        return self::SUCCESS;
    }
}
