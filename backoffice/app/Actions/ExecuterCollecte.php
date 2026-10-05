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
use App\Models\Source;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Un passage de collecte d'une source : téléchargement, conservation du fichier brut, lecture, filtrage,
 * rattachement des lieux, genre (COLLECTE §1, §4, §5, §6). Les étapes suivantes (doublons, publication) arrivent aux étapes K06 à K08.
 */
class ExecuterCollecte
{
    public function __construct(private RegistreConnecteurs $registre) {}

    /**
     * @param  callable(AnnonceNormalisee, Lieu, ResultatGenre): void|null  $traiterAnnonce  suite de la chaîne (étapes suivantes)
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

                if ($traiterAnnonce !== null) {
                    $traiterAnnonce($element, $lieu, $genre);
                }
            }

            $collecte->update([
                'statut' => StatutCollecte::Reussie,
                'fin' => now(),
                ...$compteurs,
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
