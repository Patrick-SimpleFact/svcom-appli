<?php

namespace App\Console\Commands;

use App\Actions\PrioriserBoiteDeTravail;
use Illuminate\Console\Command;

/**
 * Recalcule l'ordre d'urgence de la boîte de travail : ce soir et villes pilotes d'abord (F7.10).
 */
class PrioriserBoiteCommande extends Command
{
    protected $signature = 'boite:prioriser';

    protected $description = 'Recalcule l’urgence des éléments en attente de la boîte de travail (ce soir, villes pilotes)';

    public function handle(PrioriserBoiteDeTravail $prioriser): int
    {
        $this->info("{$prioriser->handle()} élément(s) en attente classé(s) par urgence.");

        return self::SUCCESS;
    }
}
