<?php

namespace App\Console\Commands;

use App\Actions\ChercherAgendasOpenagenda;
use App\Models\Ville;
use App\Support\Texte;
use Illuminate\Console\Command;

/**
 * Amorçage de la liste des agendas OpenAgenda : par défaut pour les villes pilotes, ou pour une commune donnée.
 */
class ChercherAgendasOpenagendaCommande extends Command
{
    protected $signature = 'openagenda:chercher-agendas {ville? : nom de la commune (par défaut : les villes pilotes)} {--max=100 : agendas au plus par commune}';

    protected $description = 'Ajoute à la liste suivie les agendas OpenAgenda d’une commune (ou des villes pilotes)';

    public function handle(ChercherAgendasOpenagenda $chercher): int
    {
        $villes = $this->argument('ville')
            ? Ville::where('nom_normalise', Texte::normaliser($this->argument('ville')))->orderByDesc('population')->limit(1)->get()
            : Ville::where('est_pilote', true)->get();

        if ($villes->isEmpty()) {
            $this->error('Aucune commune trouvée.');

            return self::FAILURE;
        }

        foreach ($villes as $ville) {
            $resultat = $chercher->handle($ville, (int) $this->option('max'));
            $this->info("{$ville->nom} : {$resultat['trouves']} agendas trouvés, {$resultat['ajoutes']} ajoutés.");
        }

        return self::SUCCESS;
    }
}
