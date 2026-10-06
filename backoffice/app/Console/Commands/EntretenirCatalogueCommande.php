<?php

namespace App\Console\Commands;

use App\Actions\EntretenirCatalogue;
use Illuminate\Console\Command;

/**
 * Retraits des sources muettes, historique allégé et spectacles vides (COLLECTE §8.2, F7.15).
 */
class EntretenirCatalogueCommande extends Command
{
    protected $signature = 'catalogue:entretenir';

    protected $description = 'Retire les offres des sources muettes depuis 48 h, allège l’historique (30 jours) et supprime les spectacles vides';

    public function handle(EntretenirCatalogue $entretien): int
    {
        $resultat = $entretien->handle();

        $muettes = $resultat['sources_muettes'] === [] ? 'aucune' : implode(', ', $resultat['sources_muettes']);
        $this->info("Sources muettes : {$muettes} ({$resultat['nb_retires']} représentations retirées).");
        $this->info("Historique : {$resultat['offres_supprimees']} offres supprimées. Spectacles vides supprimés : {$resultat['spectacles_supprimes']}. Collectes interrompues : {$resultat['collectes_interrompues']}. Séances au-delà de l’horizon supprimées : {$resultat['hors_horizon_supprimees']}.");

        return self::SUCCESS;
    }
}
