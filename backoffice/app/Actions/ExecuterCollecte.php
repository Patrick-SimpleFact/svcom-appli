<?php

namespace App\Actions;

use App\Collecte\AnnonceNormalisee;
use App\Collecte\LigneIllisible;
use App\Collecte\RegistreConnecteurs;
use App\Enums\StatutCollecte;
use App\Models\Collecte;
use App\Models\Source;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Un passage de collecte d'une source : téléchargement, conservation du fichier brut, lecture (COLLECTE §1).
 * Les étapes suivantes (tri, lieux, genres, doublons, publication) arrivent aux étapes K03 à K08.
 */
class ExecuterCollecte
{
    public function __construct(private RegistreConnecteurs $registre) {}

    /**
     * @param  callable(AnnonceNormalisee): void|null  $traiterAnnonce  suite de la chaîne (étapes suivantes)
     */
    public function handle(Source $source, int $essai = 1, ?callable $traiterAnnonce = null): Collecte
    {
        $collecte = Collecte::create([
            'source_id' => $source->id,
            'debut' => now(),
            'statut' => StatutCollecte::EnCours,
            'essai' => $essai,
        ]);

        try {
            $connecteur = $this->registre->pour($source);
            $brut = $connecteur->telecharger($source);

            $chemin = sprintf('%s/%s-collecte-%d.%s', $source->code, now()->format('Y-m-d_His'), $collecte->id, $connecteur->extensionBrut());
            Storage::disk(config('collecte.disque_bruts'))->put($chemin, $brut);
            $collecte->update(['fichier_brut' => $chemin]);

            $recus = 0;
            $illisibles = 0;

            foreach ($connecteur->lire($brut, $source) as $element) {
                if ($element instanceof LigneIllisible) {
                    $illisibles++;

                    continue;
                }

                $recus++;

                if ($traiterAnnonce !== null) {
                    $traiterAnnonce($element);
                }
            }

            $collecte->update([
                'statut' => StatutCollecte::Reussie,
                'fin' => now(),
                'nb_recus' => $recus,
                'nb_illisibles' => $illisibles,
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
