<?php

namespace App\Console\Commands;

use App\Actions\ImporterVilles;
use Illuminate\Console\Command;

class ImporterVillesCommande extends Command
{
    protected $signature = 'villes:importer';

    protected $description = 'Importe ou met à jour les communes françaises (geo.api.gouv.fr), outre-mer compris';

    public function handle(ImporterVilles $importer): int
    {
        $this->info('Téléchargement des communes…');
        $resultat = $importer->handle();
        $this->info("Terminé : {$resultat['creees']} communes créées, {$resultat['mises_a_jour']} mises à jour.");

        return self::SUCCESS;
    }
}
