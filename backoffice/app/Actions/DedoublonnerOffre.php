<?php

namespace App\Actions;

use App\Collecte\ComparaisonLieux;
use App\Collecte\ComparaisonSeances;
use App\Enums\FileATraiter;
use App\Enums\PrecisionPosition;
use App\Enums\TypeDecisionDedoublonnage;
use App\Models\DecisionDedoublonnage;
use App\Models\ElementATraiter;
use App\Models\Lieu;
use App\Models\Offre;
use App\Models\Parametre;
use Illuminate\Support\Collection;

/**
 * Étape « déduplication » de la chaîne (COLLECTE §7) : l'offre est-elle une séance déjà connue (vendue par une
 * autre source, ou deux fois par la même) ?
 * - ressemblance forte → fusion automatique (rattachée au groupe), et si les heures diffèrent : « Fusions à contrôler » ;
 * - un critère limite → « Doublons probables », les deux restent séparées ;
 * - faible → rien.
 * Une décision manuelle (fusionner, séparer) l'emporte toujours et n'est jamais défaite par la machine.
 */
class DedoublonnerOffre
{
    public const FUSION = 'fusion';

    public const PROBABLE = 'probable';

    /** Deux lieux à moins de cette distance accueillent la même séance (COLLECTE §7.1). */
    private const LIEUX_PROCHES_METRES = 500;

    /** @var array<int, list<int>> lieux considérés comme le même, par lieu (calculés une fois par collecte) */
    private array $lieuxProches = [];

    public function __construct(
        private ComparaisonSeances $seances,
        private ComparaisonLieux $lieux,
    ) {}

    /** @return string|null « fusion », « probable » ou null */
    public function handle(Offre $offre): ?string
    {
        // Une séance qui a changé repart de zéro ; celles qui l'avaient rejointe sont réexaminées ensuite.
        $rattachees = Offre::where('meme_seance_que_id', $offre->id)->get();
        Offre::whereKey($rattachees->modelKeys())->update(['meme_seance_que_id' => null]);
        $offre->update(['meme_seance_que_id' => null]);

        $resultat = $this->rapprocher($offre);

        foreach ($rattachees as $autre) {
            $this->rapprocher($autre->fresh());
        }

        return $resultat;
    }

    private function rapprocher(Offre $offre): ?string
    {
        // 1. Une fusion décidée à la main est réappliquée.
        if ($decidee = $this->fusionDecidee($offre)) {
            $offre->update(['meme_seance_que_id' => $decidee->premiereDuGroupe()->id]);

            return self::FUSION;
        }

        $separees = $this->separationsDecidees($offre);
        $fusions = collect();
        $probables = collect();

        foreach ($this->candidates($offre) as $candidate) {
            $groupe = $candidate->meme_seance_que_id ?? $candidate->id;

            if ($separees->intersect($this->membresDuGroupe($groupe))->isNotEmpty()) {
                continue; // séparées à la main : jamais réunies, même par un autre membre du groupe
            }

            $comparaison = $this->comparer($offre, $candidate);

            if ($comparaison['decision'] === self::FUSION) {
                $fusions->push($comparaison);
            } elseif ($comparaison['decision'] === self::PROBABLE) {
                $probables->push($comparaison);
            }
        }

        if ($fusions->isNotEmpty()) {
            $meilleure = $fusions->sortBy([['ressemblance', 'desc'], ['ecart', 'asc']])->first();
            $offre->update(['meme_seance_que_id' => $meilleure['candidate']->meme_seance_que_id ?? $meilleure['candidate']->id]);

            if (($meilleure['ecart'] ?? 0) > 0 && Parametre::valeur('controle_fusions_actif')) {
                $this->signaler(FileATraiter::FusionAControler, $meilleure['candidate'], $offre, $meilleure);
            }

            return self::FUSION;
        }

        foreach ($probables as $probable) {
            $this->signaler(FileATraiter::DoublonProbable, $probable['candidate'], $offre, $probable);
        }

        return $probables->isNotEmpty() ? self::PROBABLE : null;
    }

    /** @return array{candidate: Offre, decision: ?string, ecart: ?int, ressemblance: float} */
    private function comparer(Offre $offre, Offre $candidate): array
    {
        $ecart = $offre->heure_connue && $candidate->heure_connue
            ? (int) round(abs($offre->debut->diffInMinutes($candidate->debut)))
            : null;
        $ressemblance = $this->seances->ressemblanceTitres($offre->titre_comparable, $candidate->titre_comparable);

        $niveaux = [
            $this->seances->niveauHeure($ecart, (int) Parametre::valeur('dedoublonnage_ecart_minutes')),
            $this->seances->niveauTitre($ressemblance),
        ];

        $decision = match (true) {
            in_array(ComparaisonSeances::FAIBLE, $niveaux, true) => null,
            in_array(ComparaisonSeances::LIMITE, $niveaux, true) => self::PROBABLE,
            default => self::FUSION,
        };

        return ['candidate' => $candidate, 'decision' => $decision, 'ecart' => $ecart, 'ressemblance' => round($ressemblance, 2)];
    }

    /** Offres du même jour, dans le même lieu ou un lieu considéré comme le même. */
    private function candidates(Offre $offre): Collection
    {
        if ($offre->lieu_id === null || $offre->date_locale === null) {
            return collect();
        }

        return Offre::whereKeyNot($offre->id)
            ->whereNull('disparue_le')
            ->where('date_locale', $offre->date_locale->format('Y-m-d'))
            ->whereIn('lieu_id', $this->lieuxProches($offre->lieu_id))
            ->orderBy('id')
            ->get();
    }

    /** Même lieu rattaché, lieux à moins de 500 m, ou noms de salle proches dans la même commune (COLLECTE §7.1). */
    private function lieuxProches(int $lieuId): array
    {
        if (isset($this->lieuxProches[$lieuId])) {
            return $this->lieuxProches[$lieuId];
        }

        $lieu = Lieu::find($lieuId);
        $proches = collect([$lieuId]);

        if ($lieu?->position !== null && $lieu->precision_position !== PrecisionPosition::Commune) {
            $proches = $proches->merge(Lieu::where('precision_position', '!=', PrecisionPosition::Commune)
                ->whereRaw('ST_DWithin(position, ?::geography, ?)', [$lieu->position->versEwkt(), self::LIEUX_PROCHES_METRES])
                ->pluck('id'));
        }

        if ($lieu?->ville_id !== null) {
            $proches = $proches->merge(Lieu::where('ville_id', $lieu->ville_id)->get()
                ->filter(fn (Lieu $autre) => $this->lieux->nomsProches($autre->nom, $lieu->nom))
                ->modelKeys());
        }

        return $this->lieuxProches[$lieuId] = $proches->unique()->values()->all();
    }

    private function membresDuGroupe(int $premiereId): Collection
    {
        return Offre::where('meme_seance_que_id', $premiereId)->pluck('id')->push($premiereId);
    }

    private function fusionDecidee(Offre $offre): ?Offre
    {
        $decision = DecisionDedoublonnage::where('type', TypeDecisionDedoublonnage::Fusionner)
            ->where(fn ($q) => $q->where('offre_a_id', $offre->id)->orWhere('offre_b_id', $offre->id))
            ->first();

        if ($decision === null) {
            return null;
        }

        $autre = Offre::find($decision->offre_a_id === $offre->id ? $decision->offre_b_id : $decision->offre_a_id);

        // On ne se rattache pas à une offre qui nous est elle-même rattachée.
        return $autre !== null && $autre->meme_seance_que_id !== $offre->id ? $autre : null;
    }

    private function separationsDecidees(Offre $offre): Collection
    {
        return DecisionDedoublonnage::where('type', TypeDecisionDedoublonnage::Separer)
            ->where(fn ($q) => $q->where('offre_a_id', $offre->id)->orWhere('offre_b_id', $offre->id))
            ->get()
            ->toBase()
            ->map(fn (DecisionDedoublonnage $d) => $d->offre_a_id === $offre->id ? $d->offre_b_id : $d->offre_a_id);
    }

    /** Ajoute la paire à une file de la boîte de travail (une seule fois par paire). */
    private function signaler(FileATraiter $file, Offre $connue, Offre $nouvelle, array $comparaison): void
    {
        $existe = ElementATraiter::where('file', $file)
            ->where('cible_type', $nouvelle->getMorphClass())
            ->where('cible_id', $nouvelle->id)
            ->where('donnees->offre_a_id', $connue->id)
            ->exists();

        if ($existe) {
            return;
        }

        ElementATraiter::create([
            'file' => $file,
            'cible_type' => $nouvelle->getMorphClass(),
            'cible_id' => $nouvelle->id,
            'donnees' => [
                'offre_a_id' => $connue->id,
                'offre_b_id' => $nouvelle->id,
                'ecart_minutes' => $comparaison['ecart'],
                'ressemblance' => $comparaison['ressemblance'],
            ],
        ]);
    }
}
