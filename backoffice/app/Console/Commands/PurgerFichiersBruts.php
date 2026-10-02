<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Supprime les fichiers bruts des collectes au-delà de la durée de conservation (30 jours, F7.15).
 */
class PurgerFichiersBruts extends Command
{
    protected $signature = 'collecte:purger-bruts';

    protected $description = 'Supprime les fichiers bruts de collecte de plus de 30 jours';

    public function handle(): int
    {
        $disque = Storage::disk(config('collecte.disque_bruts'));
        $limite = now()->subDays(config('collecte.conservation_bruts_jours'))->getTimestamp();
        $supprimes = 0;

        foreach ($disque->allFiles() as $fichier) {
            if ($disque->lastModified($fichier) < $limite) {
                $disque->delete($fichier);
                $supprimes++;
            }
        }

        $this->info("{$supprimes} fichiers bruts supprimés.");

        return self::SUCCESS;
    }
}
