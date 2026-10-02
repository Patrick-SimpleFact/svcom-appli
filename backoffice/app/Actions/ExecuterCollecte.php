<?php

namespace App\Actions;

use App\Collecte\AnnonceNormalisee;
use App\Collecte\LigneIllisible;
use App\Collecte\RegistreConnecteurs;
use App\Enums\IssueFiltrage;
use App\Enums\StatutCollecte;
use App\Models\Collecte;
use App\Models\Source;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Un passage de collecte d'une source : téléchargement, conservation du fichier brut, lecture, filtrage (COLLECTE §1, §5).
 * Les étapes suivantes (lieux, genres, doublons, publication) arrivent aux étapes K04 à K08.
 */
class ExecuterCollecte
{
    public function __construct(private RegistreConnecteurs $registre) {}

    /**
     * @param  callable(AnnonceNormalisee): void|null  $traiterAnnonce  suite de la chaîne (étapes suivantes)
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

                if ($issue === IssueFiltrage::Garde && $traiterAnnonce !== null) {
                    $traiterAnnonce($element);
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
