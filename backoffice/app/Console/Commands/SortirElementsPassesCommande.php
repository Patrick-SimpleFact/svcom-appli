<?php

namespace App\Console\Commands;

use App\Actions\SortirElementsPasses;
use App\Enums\FileATraiter;
use Illuminate\Console\Command;

/**
 * Vide la boîte de travail des éléments dont les séances sont toutes passées (« sans objet »).
 */
class SortirElementsPassesCommande extends Command
{
    protected $signature = 'boite:sortir-passes {--simulation : compte seulement, ne modifie rien}';

    protected $description = 'Sort de la boîte de travail (À trier, À classer, Doublons, Fusions) les éléments dont toutes les séances sont passées';

    public function handle(SortirElementsPasses $sortir): int
    {
        $simulation = (bool) $this->option('simulation');
        $resultat = $sortir->handle($simulation);

        $this->table(['File', $simulation ? 'Seraient sortis' : 'Sortis'], collect($resultat)
            ->map(fn (int $nombre, string $file) => [FileATraiter::from($file)->getLabel(), $nombre])
            ->push(['Total', array_sum($resultat)])
            ->values()->all());

        if ($simulation) {
            $this->info('Simulation : rien n’a été modifié.');
        }

        return self::SUCCESS;
    }
}
