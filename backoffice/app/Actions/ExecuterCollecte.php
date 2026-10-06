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
use App\Support\Horizon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Un passage de collecte d'une source : téléchargement, conservation du fichier brut, lecture, filtrage,
 * rattachement des lieux, genre, enregistrement de l'offre, regroupement des séances et rattachement au spectacle
 * puis publication des représentations, en une fois, si tout s'est bien passé (COLLECTE §1, §4 à §8).
 */
class ExecuterCollecte
{
    private const TAILLE_LOT = 500;

    public function __construct(private RegistreConnecteurs $registre) {}

    /**
     * @param  callable(AnnonceNormalisee, Lieu, ResultatGenre, Offre): void|null  $traiterAnnonce  suite de la chaîne (étapes suivantes)
     */
    /** Une seule collecte à la fois, toutes sources confondues (deux publications simultanées peuvent s'interbloquer). */
    public const VERROU = 'collecte-en-cours';

    /** Durée de vie du verrou : au-delà de la durée maximale d'une collecte, pour qu'un plantage ne le laisse pas bloqué. */
    public const VERROU_SECONDES = 7500;

    public function handle(Source $source, int $essai = 1, ?callable $traiterAnnonce = null, ?string $version = null): Collecte
    {
        // Si une autre collecte est en cours, on attend qu'elle soit entièrement terminée (publication comprise).
        return Cache::lock(self::VERROU, self::VERROU_SECONDES)
            ->block((int) config('collecte.attente_max_secondes'), fn () => $this->executer($source, $essai, $traiterAnnonce, $version));
    }

    private function executer(Source $source, int $essai, ?callable $traiterAnnonce, ?string $version): Collecte
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
            $offresARepublier = [];
            $compteurs = ['nb_recus' => 0, 'nb_illisibles' => 0, 'nb_retenus' => 0, 'nb_exclus' => 0, 'nb_a_trier' => 0, 'nb_hors_horizon' => 0];
            $horizon = Horizon::dateLimite(); // séances au-delà : ni enregistrées ni publiées

            // Écritures groupées par lots (une transaction pour 500 annonces) : bien plus rapide sur les gros flux.
            $niveau = DB::transactionLevel();
            DB::beginTransaction();
            $dansLeLot = 0;

            foreach ($connecteur->lire($brut, $source) as $element) {
                if (++$dansLeLot === self::TAILLE_LOT) {
                    DB::commit();
                    DB::beginTransaction();
                    $dansLeLot = 0;
                }

                if ($element instanceof LigneIllisible) {
                    $compteurs['nb_illisibles']++;

                    continue;
                }

                $compteurs['nb_recus']++;

                if ($horizon !== null && $element->debut->gt($horizon)) {
                    $compteurs['nb_hors_horizon']++;

                    continue;
                }

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
                [$offre, $seanceChangee, $contenuChange] = $enregistrer->handle($element, $source, $lieu, $genre, $collecte);

                // À republier : ce qui a changé, et ce qui n'a jamais été publié (collecte précédente interrompue).
                if ($contenuChange || $offre->representation_id === null) {
                    $offresARepublier[] = $offre->id;
                }

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

            DB::commit();

            $publication = app(PublierSource::class)->handle($source, $collecte, $offresARepublier);

            $source->update(['dernier_contact_le' => now()]);
            $collecte->update([
                'statut' => StatutCollecte::Reussie,
                'fin' => now(),
                ...$compteurs,
                ...$publication,
            ]);
        } catch (Throwable $erreur) {
            while (DB::transactionLevel() > ($niveau ?? DB::transactionLevel())) {
                DB::rollBack(); // le lot en cours est annulé ; les offres des lots précédents restent préparées (non publiées)
            }

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
