<?php

namespace App\Console\Commands;

use App\Mesure\Evenements;
use App\Mesure\ResumesQuotidiens;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Chaque nuit (SCHEMA §9) : résume la veille dans stats_quotidiennes, prépare la partition du mois suivant,
 * puis supprime les événements de plus de 13 mois.
 */
class MesureQuotidienneCommande extends Command
{
    protected $signature = 'mesure:quotidienne {--jour= : jour à résumer (AAAA-MM-JJ), la veille par défaut}';

    protected $description = 'Résume une journée de mesure et purge les événements de plus de 13 mois';

    public function handle(ResumesQuotidiens $resumes, Evenements $evenements): int
    {
        $jour = $this->option('jour')
            ? CarbonImmutable::createFromFormat('!Y-m-d', $this->option('jour'), 'Europe/Paris')
            : CarbonImmutable::now('Europe/Paris')->subDay();

        $this->info("{$resumes->calculer($jour)} chiffre(s) enregistré(s) pour le {$jour->format('d/m/Y')}.");

        $evenements->assurerPartition(CarbonImmutable::now()->addMonth());
        $purgees = $evenements->purger();
        $this->info(count($purgees) === 0 ? 'Rien à purger.' : 'Mois supprimés : '.implode(', ', $purgees));

        return self::SUCCESS;
    }
}
