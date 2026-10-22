<?php

namespace App\Ia;

use App\Enums\FileATraiter;
use App\Enums\ModeIa;
use App\Enums\StatutPropositionIa;
use App\Ia\Taches\ClasserCategories;
use App\Ia\Taches\ControlerFusions;
use App\Ia\Taches\ControlerSpectacles;
use App\Ia\Taches\DepartagerDoublons;
use App\Ia\Taches\TacheIa;
use App\Ia\Taches\TrierAnnonces;
use App\Models\ElementATraiter;
use App\Models\Parametre;
use App\Models\PropositionIa;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Le moteur de l'assistant IA (plan-module-ia v2) : pour chaque file dont le mode n'est pas « Désactivé »,
 * chaque lot d'éléments passe par une cascade de modèles.
 * - 1er niveau : deux modèles peu coûteux. Leur décision est retenue s'ils répondent la même chose, tous deux sûrs
 *   (confiance minimale, 90 % par défaut).
 * - 2e niveau, pour le reste seulement : un modèle plus solide, retenu s'il est sûr.
 * - Sinon, ou si un garde-fou de la file s'y oppose, l'élément est « à décider » : il va dans « Mes actions du jour ».
 * Chaque réponse de modèle est gardée comme « avis » ; la décision de la cascade est une proposition, appliquée en mode Actif.
 * Avant chaque lot, le garde-fou du forfait (Quota) doit l'autoriser ; sinon tout s'arrête jusqu'au passage suivant.
 */
class Assistant
{
    /** @var array<string, class-string<TacheIa>> par file (valeur de FileATraiter) */
    public const TACHES = [
        'a_classer' => ClasserCategories::class,
        'a_trier' => TrierAnnonces::class,
        'doublon_probable' => DepartagerDoublons::class,
        'fusion_a_controler' => ControlerFusions::class,
        'spectacle_a_controler' => ControlerSpectacles::class,
    ];

    public function __construct(private ClientOllama $client, private Quota $quota) {}

    /** @return list<string> les modèles du 1er niveau (réglage « ia_modeles_niveau1 », séparés par des virgules) */
    public static function modelesNiveau1(): array
    {
        return self::liste((string) Parametre::valeur('ia_modeles_niveau1'));
    }

    public static function modeleNiveau2(): ?string
    {
        return self::liste((string) Parametre::valeur('ia_modele_niveau2'))[0] ?? null;
    }

    /** @return list<string> */
    public static function liste(string $valeur): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $valeur)))));
    }

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
     * @param  int  $lots  nombre maximal de lots par file
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

        if (self::modelesNiveau1() === []) {
            return ['Arrêt : aucun modèle de 1er niveau dans les réglages.'];
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
                    $journal[] = "{$tache->file()->getLabel()} : plus rien à examiner.";
                    break;
                }

                try {
                    $journal[] = $this->traiterLot($tache, $elements);
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
        $seuil = (int) Parametre::valeur('ia_confiance_min_pct');
        $niveau1 = self::modelesNiveau1();
        $avis = $this->avis($tache, $elements, $niveau1, 1);

        // 1er niveau : tous les modèles ont répondu la même chose, et chacun est sûr.
        $retenus = [];
        foreach ($elements as $element) {
            $reponses = collect($avis[$element->id] ?? []);

            if ($reponses->count() === count($niveau1)
                && $reponses->map(fn (PropositionIa $p) => $tache->cle($p->proposition))->unique()->count() === 1
                && $reponses->min('confiance') >= $seuil) {
                $retenus[$element->id] = [$reponses->sortBy('confiance')->first(), 1];
            }
        }

        // 2e niveau, pour ce que le 1er n'a pas tranché.
        $restants = $elements->reject(fn (ElementATraiter $e) => isset($retenus[$e->id]));
        if ($restants->isNotEmpty() && ($modele2 = self::modeleNiveau2())) {
            foreach ($this->avis($tache, $restants, [$modele2], 2) as $id => $reponses) {
                $avis[$id] = [...($avis[$id] ?? []), ...$reponses];
                $reponse = $reponses[$modele2] ?? null;
                if ($reponse !== null && $reponse->confiance >= $seuil) {
                    $retenus[$id] = [$reponse, 2];
                }
            }
        }

        $actif = $tache->mode() === ModeIa::Actif;
        [$decidees, $appliquees, $doutes] = [0, 0, 0];

        foreach ($elements as $element) {
            $reponses = $avis[$element->id] ?? [];
            if ($reponses === []) {
                continue; // aucun modèle n'a répondu : l'élément sera redemandé après le délai d'échec
            }

            [$retenue, $niveau] = $retenus[$element->id] ?? [null, null];
            $gardeFou = $retenue ? $tache->gardeFou($element, $retenue->proposition) : null;

            if ($retenue === null || $gardeFou !== null) {
                PropositionIa::create([
                    'element_a_traiter_id' => $element->id,
                    'file' => $tache->file(),
                    'proposition' => $retenue?->proposition ?? [],
                    'confiance' => $retenue?->confiance ?? 0,
                    'justification' => mb_strimwidth($gardeFou !== null ? "Garde-fou : {$gardeFou}" : self::resume($tache, $reponses), 0, 500, '…'),
                    'modele' => 'cascade',
                    'niveau' => $niveau,
                    'statut' => StatutPropositionIa::Doute,
                ]);
                $doutes++;

                continue;
            }

            $decision = PropositionIa::create([
                'element_a_traiter_id' => $element->id,
                'appel_ia_id' => $retenue->appel_ia_id,
                'file' => $tache->file(),
                'proposition' => $retenue->proposition,
                'confiance' => $retenue->confiance,
                'justification' => $retenue->justification,
                'modele' => 'cascade',
                'niveau' => $niveau,
                'statut' => StatutPropositionIa::Proposee,
            ]);
            $decidees++;

            if ($actif) {
                try {
                    app(AppliquerProposition::class)->handle($decision);
                    $appliquees++;
                } catch (Throwable $e) {
                    report($e); // la décision reste proposée, à accepter par un humain
                }
            }
        }

        return sprintf('%s : %d élément(s), %d décidé(s)%s, %d à décider par Patrick.', $tache->file()->getLabel(), $elements->count(),
            $decidees, $actif ? " ({$appliquees} appliqué(s))" : ' (observation)', $doutes);
    }

    /**
     * Demande le lot à chaque modèle et garde ses réponses comme avis.
     *
     * @param  Collection<int, ElementATraiter>  $elements
     * @param  list<string>  $modeles
     * @return array<int, array<string, PropositionIa>> par élément, puis par modèle
     */
    private function avis(TacheIa $tache, Collection $elements, array $modeles, int $niveau): array
    {
        $avis = [];

        foreach ($modeles as $modele) {
            try {
                $reponse = $this->client->demander($tache->file()->value, $tache->messages($elements), $tache->schema(), $elements->count(), $modele);
            } catch (ErreurReponseIa $e) {
                // Réponse hors cadre pour tout le lot : ce modèle n'a pas d'avis ; les autres niveaux décideront.
                $this->echec($tache, $elements, $e->getMessage(), $modele, $niveau);

                continue;
            }

            $resultats = collect($reponse->contenu['resultats'] ?? [])->filter(fn ($r) => is_array($r) && isset($r['id']))->keyBy(fn ($r) => (int) $r['id']);

            foreach ($elements as $element) {
                $resultat = $resultats->get($element->id);
                $proposition = $resultat ? $tache->lireResultat($resultat, $element) : null;

                if ($proposition === null) {
                    $this->echec($tache, collect([$element]), $resultat ? 'Résultat hors cadre : '.json_encode($resultat, JSON_UNESCAPED_UNICODE) : 'Aucun résultat pour cet élément.', $modele, $niveau, $reponse->appel->id);

                    continue;
                }

                $avis[$element->id][$modele] = PropositionIa::create([
                    'element_a_traiter_id' => $element->id,
                    'appel_ia_id' => $reponse->appel->id,
                    'file' => $tache->file(),
                    'proposition' => $proposition,
                    'confiance' => max(0, min(100, (int) ($resultat['confiance'] ?? 0))),
                    'justification' => mb_strimwidth(trim((string) ($resultat['justification'] ?? '')), 0, 500, '…') ?: null,
                    'modele' => $modele,
                    'niveau' => $niveau,
                    'statut' => StatutPropositionIa::Avis,
                ]);
            }
        }

        return $avis;
    }

    /**
     * Les avis en une ligne, pour Patrick : « gpt-oss : Même séance (85 %) · deepseek-v4.1-flash : Séances différentes (70 %) ».
     *
     * @param  array<string, PropositionIa>  $reponses
     */
    public static function resume(TacheIa $tache, array $reponses): string
    {
        return collect($reponses)
            ->map(fn (PropositionIa $p, string $modele) => self::nomCourt($modele).' : '.$tache->libelle($p)." ({$p->confiance} %)")
            ->implode(' · ');
    }

    /** « gpt-oss:120b-cloud » → « gpt-oss » ; « deepseek-v4.1-flash:cloud » → « deepseek-v4.1-flash ». */
    public static function nomCourt(string $modele): string
    {
        return (string) preg_replace('/[:-]cloud$/', '', explode(':', $modele)[0]);
    }

    /** @param  Collection<int, ElementATraiter>  $elements */
    private function echec(TacheIa $tache, Collection $elements, string $motif, string $modele, int $niveau, ?int $appel = null): void
    {
        foreach ($elements as $element) {
            PropositionIa::create([
                'element_a_traiter_id' => $element->id,
                'appel_ia_id' => $appel,
                'file' => $tache->file(),
                'proposition' => ['erreur' => mb_strimwidth($motif, 0, 300, '…')],
                'confiance' => 0,
                'modele' => $modele,
                'niveau' => $niveau,
                'statut' => StatutPropositionIa::Echec,
            ]);
        }
    }
}
