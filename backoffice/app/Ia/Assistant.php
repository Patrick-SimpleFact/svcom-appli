<?php

namespace App\Ia;

use App\Enums\FileATraiter;
use App\Enums\ModeIa;
use App\Enums\StatutPropositionIa;
use App\Ia\Taches\ClasserCategories;
use App\Ia\Taches\TacheIa;
use App\Models\ElementATraiter;
use App\Models\Parametre;
use App\Models\PropositionIa;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Le moteur de l'assistant IA (plan-module-ia) : pour chaque file dont le mode n'est pas « Désactivé »,
 * envoie des lots d'éléments au modèle, enregistre ses propositions et, en mode Actif, applique celles dont il est sûr.
 * Avant chaque lot, le garde-fou du forfait (Quota) doit l'autoriser ; sinon tout s'arrête jusqu'au passage suivant.
 */
class Assistant
{
    /** @var array<string, class-string<TacheIa>> par file (valeur de FileATraiter) */
    public const TACHES = [
        'a_classer' => ClasserCategories::class,
    ];

    public function __construct(private ClientOllama $client, private Quota $quota) {}

    public function tache(FileATraiter $file): TacheIa
    {
        $classe = self::TACHES[$file->value] ?? throw new InvalidArgumentException("L’assistant ne traite pas la file « {$file->getLabel()} ».");

        return app($classe);
    }

    /** @return Collection<int, TacheIa> */
    public function taches(): Collection
    {
        return collect(self::TACHES)->map(fn (string $classe) => app($classe))->values();
    }

    /**
     * @param  int  $lots  nombre maximal de demandes au modèle par file
     * @return list<string> compte rendu, une ligne par événement
     */
    public function traiter(int $lots, ?FileATraiter $seulement = null): array
    {
        $journal = [];
        $taches = $this->taches()
            ->filter(fn (TacheIa $t) => $seulement === null || $t->file() === $seulement)
            ->filter(fn (TacheIa $t) => $t->mode() !== ModeIa::Desactive);

        if ($taches->isEmpty()) {
            return ['Aucune file active : rien à faire.'];
        }

        foreach ($taches as $tache) {
            if (($constatees = $tache->constater()) > 0) {
                $journal[] = "{$tache->file()->getLabel()} : {$constatees} proposition(s) rapprochée(s) des décisions prises entre-temps.";
            }

            for ($lot = 1; $lot <= $lots; $lot++) {
                $decision = $this->quota->verifier();
                if (! $decision->autorise) {
                    $journal[] = 'Arrêt : '.$decision->motif.($decision->repriseLe ? ' Reprise après le '.$decision->repriseLe->setTimezone('Europe/Paris')->format('d/m à H:i').'.' : '');

                    return $journal;
                }

                $elements = $tache->aTraiter((int) Parametre::valeur('ia_taille_lot'));
                if ($elements->isEmpty()) {
                    $journal[] = "{$tache->file()->getLabel()} : plus rien à proposer.";
                    break;
                }

                try {
                    $journal[] = $this->traiterLot($tache, $elements);
                } catch (ErreurReponseIa $e) {
                    // Le modèle a répondu hors cadre pour tout le lot : ces éléments attendront avant d'être redemandés.
                    $this->echec($tache, $elements, $e->getMessage(), (string) Parametre::valeur('ia_modele'));
                    $journal[] = "{$tache->file()->getLabel()} : lot sans réponse exploitable ({$e->getMessage()}).";
                } catch (ErreurIa $e) {
                    // Ollama injoignable, erreur du serveur… : on s'arrête, on réessaiera au passage suivant.
                    $journal[] = "Arrêt : {$e->getMessage()}";

                    return $journal;
                }
            }
        }

        return $journal;
    }

    /** @param  Collection<int, ElementATraiter>  $elements */
    private function traiterLot(TacheIa $tache, Collection $elements): string
    {
        $reponse = $this->client->demander($tache->file()->value, $tache->messages($elements), $tache->schema(), $elements->count());
        $resultats = collect($reponse->contenu['resultats'] ?? [])->filter(fn ($r) => is_array($r) && isset($r['id']))->keyBy(fn ($r) => (int) $r['id']);

        $actif = $tache->mode() === ModeIa::Actif;
        $seuil = (int) Parametre::valeur('ia_confiance_min_pct');
        [$proposees, $appliquees, $echecs] = [0, 0, 0];

        foreach ($elements as $element) {
            $resultat = $resultats->get($element->id);
            $proposition = $resultat ? $tache->lireResultat($resultat, $element) : null;

            if ($proposition === null) {
                $this->echec($tache, collect([$element]), $resultat ? 'Résultat hors cadre : '.json_encode($resultat, JSON_UNESCAPED_UNICODE) : 'Aucun résultat pour cet élément.', $reponse->modele, $reponse->appel->id);
                $echecs++;

                continue;
            }

            $enregistree = PropositionIa::create([
                'element_a_traiter_id' => $element->id,
                'appel_ia_id' => $reponse->appel->id,
                'file' => $tache->file(),
                'proposition' => $proposition,
                'confiance' => max(0, min(100, (int) ($resultat['confiance'] ?? 0))),
                'justification' => mb_strimwidth(trim((string) ($resultat['justification'] ?? '')), 0, 500, '…') ?: null,
                'modele' => $reponse->modele,
                'statut' => StatutPropositionIa::Proposee,
            ]);
            $proposees++;

            if ($actif && $enregistree->confiance >= $seuil) {
                try {
                    app(AppliquerProposition::class)->handle($enregistree);
                    $appliquees++;
                } catch (Throwable $e) {
                    report($e); // la proposition reste à décider par un humain
                }
            }
        }

        return sprintf('%s : %d proposition(s)%s%s.', $tache->file()->getLabel(), $proposees,
            $actif ? ", {$appliquees} appliquée(s)" : '', $echecs > 0 ? ", {$echecs} sans réponse" : '');
    }

    /** @param  Collection<int, ElementATraiter>  $elements */
    private function echec(TacheIa $tache, Collection $elements, string $motif, string $modele, ?int $appel = null): void
    {
        foreach ($elements as $element) {
            PropositionIa::create([
                'element_a_traiter_id' => $element->id,
                'appel_ia_id' => $appel,
                'file' => $tache->file(),
                'proposition' => ['erreur' => mb_strimwidth($motif, 0, 300, '…')],
                'confiance' => 0,
                'modele' => $modele,
                'statut' => StatutPropositionIa::Echec,
            ]);
        }
    }
}
