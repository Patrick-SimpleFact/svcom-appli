<?php

namespace App\Console\Commands;

use App\Actions\ImporterLieuxMinistere;
use Illuminate\Console\Command;

class ImporterLieuxMinistereCommande extends Command
{
    protected $signature = 'lieux:importer-ministere';

    protected $description = 'Importe les lieux de spectacle de la base Basilic du ministère de la Culture';

    public function handle(ImporterLieuxMinistere $importer): int
    {
        $this->info('Téléchargement de la base Basilic (≈ 50 Mo)…');
        $resultat = $importer->handle();
        $this->info("Terminé : {$resultat['creees']} lieux créés, {$resultat['mises_a_jour']} mis à jour, {$resultat['ignorees']} ignorés (sans position).");

        return self::SUCCESS;
    }
}
