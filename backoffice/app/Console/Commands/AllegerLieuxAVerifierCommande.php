<?php

namespace App\Console\Commands;

use App\Actions\RattacherLieu;
use App\Collecte\NomDeLieu;
use App\Enums\FileATraiter;
use App\Enums\PrecisionPosition;
use App\Enums\StatutElement;
use App\Models\ElementATraiter;
use App\Models\Lieu;
use Illuminate\Console\Command;

/**
 * À lancer une fois après la mise en ligne de L01f (13/10/2026), après lieux:reparer-noms : applique aux « Nouveau lieu,
 * absent du référentiel » déjà en attente la règle de la collecte. Bien placé et bien nommé (ou hors ville pilote) :
 * sorti de la file, « vérifié par la règle » ; nom imprécis dans une ville pilote : reste, avec ce motif.
 */
class AllegerLieuxAVerifierCommande extends Command
{
    protected $signature = 'lieux:alleger-verification {--simulation : compter sans rien modifier}';

    protected $description = 'Sort de « Lieux à vérifier » les nouveaux lieux bien placés et bien nommés';

    public function handle(): int
    {
        $simulation = (bool) $this->option('simulation');
        [$sortis, $imprecis] = [0, 0];

        $elements = ElementATraiter::where('file', FileATraiter::LieuAVerifier)->where('statut', StatutElement::EnAttente)
            ->where('donnees->motif', 'Nouveau lieu, absent du référentiel')->with('cible.ville');

        foreach ($elements->lazyById() as $element) {
            $lieu = $element->cible;

            if (! $lieu instanceof Lieu || ! in_array($lieu->precision_position, [PrecisionPosition::Exacte, PrecisionPosition::Adresse], true)) {
                continue;
            }

            if ($lieu->ville?->est_pilote && NomDeLieu::estImprecis($lieu->nom, $lieu->adresse, $lieu->ville->nom)) {
                $imprecis++;
                $simulation || $element->update(['priorite' => 1, 'donnees' => [...$element->donnees, 'motif' => RattacherLieu::MOTIF_NOM_IMPRECIS]]);

                continue;
            }

            $sortis++;
            $simulation || $element->update([
                'statut' => StatutElement::Traite,
                'decision' => ['verifie' => true, 'par_regle' => 'L01f : nom et position fiables'],
                'traite_le' => now(),
            ]);
        }

        $prefixe = $simulation ? 'Simulation : ' : '';
        $this->info("{$prefixe}{$sortis} lieu(x) sorti(s) de la file, {$imprecis} gardé(s) (nom imprécis dans une ville pilote).");

        return self::SUCCESS;
    }
}
