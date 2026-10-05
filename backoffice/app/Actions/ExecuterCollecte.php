<?php

namespace App\Actions;

use App\Collecte\AnnonceNormalisee;
use App\Collecte\LigneIllisible;
use App\Collecte\RegistreConnecteurs;
use App\Collecte\ResultatGenre;
use App\Enums\IssueFiltrage;
use App\Enums\StatutCollecte;
use App\Models\Collecte;
use App\Models\Lieu;
use App\Models\Offre;
use App\Models\Source;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Un passage de collecte d'une source : téléchargement, conservation du fichier brut, lecture, filtrage,
 * rattachement des lieux, genre, enregistrement de l'offre, regroupement des séances et rattachement au spectacle
 * puis publication des représentations, en une fois, si tout s'est bien passé (COLLECTE §1, §4 à §8).
 */
class ExecuterCollecte
{
    public function __construct(private RegistreConnecteurs $registre) {}

    /**
     * @param  callable(AnnonceNormalisee, Lieu, ResultatGenre, Offre): void|null  $traiterAnnonce  suite de la chaîne (étapes suivantes)
     */
    public function handle(Source $source, int $essai = 1, ?callable $traiterAnnonce = null, ?string $version = null): Collecte
    {
        $collecte = Collecte::create([
            'source_id' => $source->id,
            'debut' => now(),
            'statut' => StatutCollecte::EnCours,
            'essai' => $essai,
            'version_detectee' => $version,
        ]);

        try {
            $connecteur = $this->registre->pour($source);
            $brut = $connecteur->telecharger($source);

            $chemin = sprintf('%s/%s-collecte-%d.%s', $source->code, now()->format('Y-m-d_His'), $collecte->id, $connecteur->extensionBrut());
            Storage::disk(config('collecte.disque_bruts'))->put($chemin, $brut);
            $collecte->update(['fichier_brut' => $chemin]);

            $trier = app(TrierAnnonce::class); // une instance par collecte : listes de mots lues une fois
            $rattacher = app(RattacherLieu::class); // idem : lieux déjà résolus gardés en mémoire
            $classer = app(ClasserAnnonce::class); // idem : correspondances et mots de genre lus une fois
            $enregistrer = app(EnregistrerOffre::class);
            $dedoublonner = app(DedoublonnerOffre::class); // idem : lieux proches calculés une fois
            $rattacherSpectacle = app(RattacherSpectacle::class);
            $offresVues = [];
            $compteurs = ['nb_recus' => 0, 'nb_illisibles' => 0, 'nb_retenus' => 0, 'nb_exclus' => 0, 'nb_a_trier' => 0];

            foreach ($connecteur->lire($brut, $source) as $element) {
                if ($element instanceof LigneIllisible) {
                    $compteurs['nb_illisibles']++;

                    continue;
                }

                $compteurs['nb_recus']++;

                $issue = $trier->handle($element, $source);
                $compteurs[match ($issue) {
                    IssueFiltrage::Garde => 'nb_retenus',
                    IssueFiltrage::Exclu => 'nb_exclus',
                    IssueFiltrage::ATrier => 'nb_a_trier',
                }]++;

                if ($issue !== IssueFiltrage::Garde) {
                    continue;
                }

                $lieu = $rattacher->handle($element, $source);
                $genre = $classer->handle($element, $source);
                [$offre, $seanceChangee] = $enregistrer->handle($element, $source, $lieu, $genre);
                $offresVues[] = $offre->id;

                if ($seanceChangee) {
                    $dedoublonner->handle($offre);
                }

                if ($seanceChangee || $offre->spectacle_id === null) {
                    $rattacherSpectacle->handle($offre->fresh());
                    $offre->refresh();
                }

                if ($traiterAnnonce !== null) {
                    $traiterAnnonce($element, $lieu, $genre, $offre);
                }
            }

            $publication = app(PublierSource::class)->handle($source, $offresVues);

            $source->update(['dernier_contact_le' => now()]);
            $collecte->update([
                'statut' => StatutCollecte::Reussie,
                'fin' => now(),
                ...$compteurs,
                ...$publication,
            ]);
        } catch (Throwable $erreur) {
            $collecte->update([
                'statut' => StatutCollecte::Echouee,
                'fin' => now(),
                'erreur' => mb_substr($erreur->getMessage(), 0, 2000),
            ]);

            throw $erreur;
        }

        return $collecte;
    }
}
