<?php

namespace App\Console\Commands;

use App\Enums\FileATraiter;
use App\Ia\Assistant;
use App\Ia\ClientOllama;
use App\Ia\ErreurIa;
use App\Models\PropositionIa;
use Illuminate\Console\Command;

/**
 * Compare plusieurs modèles cloud sur un même échantillon d'une file, sans rien enregistrer ni modifier :
 * pour choisir le réglage « Modèle utilisé » (plan-module-ia, IA03). Consomme un peu de forfait (un appel par lot et par modèle).
 */
class ComparerModelesIaCommande extends Command
{
    protected $signature = 'ia:comparer {modeles* : modèles cloud à comparer (ex. gemma4:cloud gpt-oss:120b-cloud)}
        {--file=a_classer} {--nombre=20 : taille de l’échantillon} {--lot=10 : éléments par demande}';

    protected $description = 'Compare les propositions de plusieurs modèles cloud sur un échantillon d’une file (aucune proposition enregistrée)';

    public function handle(Assistant $assistant, ClientOllama $client): int
    {
        $tache = $assistant->tache(FileATraiter::from((string) $this->option('file')));
        $modeles = $this->argument('modeles');
        $elements = $tache->aTraiter((int) $this->option('nombre'));

        if ($elements->isEmpty()) {
            $this->warn('Aucun élément en attente dans cette file.');

            return self::SUCCESS;
        }

        $lignes = $elements->mapWithKeys(fn ($e) => [$e->id => ['id' => $e->id, 'element' => mb_strimwidth($e->donnees['categorie'] ?? (string) $e->id, 0, 40, '…')]])->all();
        $mesures = [];

        foreach ($modeles as $modele) {
            $mesures[$modele] = ['duree' => 0, 'jetons' => 0, 'erreurs' => 0];

            foreach ($elements->chunk((int) $this->option('lot')) as $lot) {
                try {
                    $reponse = $client->demander('comparaison', $tache->messages($lot), $tache->schema(), $lot->count(), $modele);
                    $mesures[$modele]['duree'] += $reponse->appel->duree_ms;
                    $mesures[$modele]['jetons'] += $reponse->appel->jetons_entree + $reponse->appel->jetons_sortie;
                    $resultats = collect($reponse->contenu['resultats'] ?? [])->keyBy(fn ($r) => (int) ($r['id'] ?? 0));
                } catch (ErreurIa $e) {
                    $this->warn("{$modele} : {$e->getMessage()}");
                    $mesures[$modele]['erreurs']++;
                    $resultats = collect();
                }

                foreach ($lot as $element) {
                    $resultat = $resultats->get($element->id);
                    $proposition = $resultat ? $tache->lireResultat($resultat, $element) : null;
                    $lignes[$element->id][$modele] = $proposition === null ? '—'
                        : $tache->libelle(new PropositionIa(['proposition' => $proposition])).' ('.(int) ($resultat['confiance'] ?? 0).')';
                }
            }
        }

        $this->table(['#', 'Élément', ...$modeles], array_map(fn ($l) => [$l['id'], $l['element'], ...array_map(fn ($m) => $l[$m] ?? '—', $modeles)], $lignes));
        $this->table(['Modèle', 'Durée totale', 'Jetons', 'Erreurs'], array_map(fn ($m) => [$m, round($mesures[$m]['duree'] / 1000, 1).' s', $mesures[$m]['jetons'], $mesures[$m]['erreurs']], $modeles));
        $this->info('Aucune proposition enregistrée, rien de modifié (les appels sont seulement journalisés).');

        return self::SUCCESS;
    }
}
