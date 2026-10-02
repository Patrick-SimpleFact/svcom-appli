<?php

namespace App\Console\Commands;

use App\Actions\DetecterMisesAJour;
use Illuminate\Console\Command;

class DetecterMisesAJourCommande extends Command
{
    protected $signature = 'collecte:detecter';

    protected $description = 'Vérifie quelles sources ont été mises à jour et lance leur collecte (toutes les 30 min)';

    public function handle(DetecterMisesAJour $detecter): int
    {
        $lancees = $detecter->handle();

        $this->info($lancees === [] ? 'Aucune source à collecter.' : 'Collecte lancée pour : '.implode(', ', $lancees));

        return self::SUCCESS;
    }
}
