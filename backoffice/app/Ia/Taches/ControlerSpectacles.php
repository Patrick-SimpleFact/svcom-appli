<?php

namespace App\Ia\Taches;

use App\Actions\SeparerSpectacle;
use App\Enums\FileATraiter;
use App\Enums\StatutPropositionIa;
use App\Models\ElementATraiter;
use App\Models\Lieu;
use App\Models\Offre;
use App\Models\PropositionIa;
use App\Models\Spectacle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * File « Spectacles à contrôler » : un spectacle regroupe des dates dans plusieurs lieux sur la foi du titre seul.
 * Même spectacle partout (SeparerSpectacle::confirmer), ou des lieux à détacher dans un nouveau spectacle (SeparerSpectacle).
 * Détacher n'est appliqué que si TOUS les modèles interrogés désignent exactement les mêmes lieux (accord de Patrick, 22/10/2026).
 */
class ControlerSpectacles extends TacheIa
{
    public const LIEUX_MAX = 25;

    public function file(): FileATraiter
    {
        return FileATraiter::SpectacleAControler;
    }

    public function cleMode(): string
    {
        return 'ia_mode_spectacles_a_controler';
    }

    protected function filtrer(Builder $requete): Builder
    {
        return $requete->where('cible_type', (new Spectacle)->getMorphClass());
    }

    public function messages(Collection $elements): array
    {
        $systeme = $this->socle()."\n\n".<<<'TXT'
            TÂCHE : un spectacle regroupe des dates dans PLUSIEURS lieux sur la foi du titre seul (les artistes ne sont pas toujours connus). Est-ce bien le MÊME spectacle (même troupe, même création, en tournée) partout ?
            « meme_spectacle » : le regroupement est juste (une tournée, un artiste connu, une même compagnie, des descriptions concordantes).
            « separer » : au moins un lieu présente visiblement un AUTRE spectacle du même titre (autre troupe, autre artiste, adaptation différente, titre banal comme « Le Misanthrope », « Peter Pan », « Spectacle de Noël » joué par des troupes différentes) ; indique alors dans "lieux_a_detacher" les lieu_id à détacher (jamais tous : il doit en rester au spectacle).
            Détacher un lieu crée un nouveau spectacle pour ses dates : en cas de doute, confiance basse.

            Format d'un résultat :
            {"id": 12, "decision": "meme_spectacle", "lieux_a_detacher": [], "confiance": 92, "justification": "…"}
            {"id": 13, "decision": "separer", "lieux_a_detacher": [481, 902], "confiance": 90, "justification": "…"}
            TXT;

        return [
            ['role' => 'system', 'content' => $systeme],
            ['role' => 'user', 'content' => json_encode(['spectacles' => $elements->map(fn (ElementATraiter $e) => [
                'id' => $e->id,
                'titre' => $e->cible?->titre ?? ($e->donnees['titre'] ?? ''),
                'lieux' => $this->lieux($e),
            ])->values()->all()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
        ];
    }

    /** Ce que chaque lieu dit du spectacle : nombre de dates, billetteries, titres, artistes, début de description. */
    private function lieux(ElementATraiter $element): array
    {
        $donnees = fn (Offre $o): array => $o->donnees_normalisees ?? [];

        return Offre::with(['source', 'lieu.ville'])->where('spectacle_id', $element->cible_id)->whereNull('disparue_le')->get()
            ->groupBy('lieu_id')->take(self::LIEUX_MAX)
            ->map(fn (Collection $offres, $lieuId) => [
                'lieu_id' => (int) $lieuId,
                'lieu' => $offres->first()->lieu?->nom,
                'ville' => $offres->first()->lieu?->ville?->nom,
                'dates' => $offres->count(),
                'sources' => $offres->pluck('source.nom')->unique()->values()->all(),
                'titres' => $offres->map(fn (Offre $o) => $donnees($o)['titre'] ?? null)->filter()->unique()->take(3)->values()->all(),
                'artistes' => $offres->flatMap(fn (Offre $o) => (array) ($donnees($o)['artistes'] ?? []))->unique()->take(5)->values()->all(),
                'description' => Str::limit(strip_tags((string) $offres->map(fn (Offre $o) => $donnees($o)['description'] ?? null)->filter()->first()), 250),
            ])->values()->all();
    }

    protected function schemaResultat(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'decision' => ['type' => 'string', 'enum' => ['meme_spectacle', 'separer']],
                'lieux_a_detacher' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
            'required' => ['decision', 'lieux_a_detacher'],
        ];
    }

    public function lireResultat(array $resultat, ElementATraiter $element): ?array
    {
        if (($resultat['decision'] ?? null) === 'meme_spectacle') {
            return ['decision' => 'meme_spectacle'];
        }

        if (($resultat['decision'] ?? null) !== 'separer') {
            return null;
        }

        // Des lieux de ce spectacle, au moins un, mais pas tous.
        $lieux = Offre::where('spectacle_id', $element->cible_id)->whereNull('disparue_le')->distinct()->pluck('lieu_id')->map(fn ($id) => (int) $id)->all();
        $detaches = collect((array) ($resultat['lieux_a_detacher'] ?? []))->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        return $detaches !== [] && array_diff($detaches, $lieux) === [] && count($detaches) < count($lieux)
            ? ['decision' => 'separer', 'lieux' => $detaches]
            : null;
    }

    public function gardeFou(ElementATraiter $element, array $proposition): ?string
    {
        if ($proposition['decision'] !== 'separer') {
            return null;
        }

        $avis = $element->propositionsIa()->where('statut', StatutPropositionIa::Avis)->get();

        return $avis->every(fn (PropositionIa $p) => $this->cle($p->proposition) === $this->cle($proposition))
            ? null
            : 'détacher des lieux exige que tous les modèles interrogés désignent les mêmes lieux';
    }

    public function appliquer(PropositionIa $proposition): void
    {
        $spectacle = Spectacle::findOrFail($proposition->element->cible_id);

        $proposition->proposition['decision'] === 'separer'
            ? app(SeparerSpectacle::class)->handle($spectacle, $proposition->proposition['lieux'])
            : app(SeparerSpectacle::class)->confirmer($spectacle);
    }

    public function libelle(PropositionIa $proposition): string
    {
        $p = $proposition->proposition;

        if (($p['decision'] ?? null) !== 'separer') {
            return 'Même spectacle partout';
        }

        return 'Détacher : '.Lieu::with('ville')->whereIn('id', $p['lieux'] ?? [])->get()
            ->map(fn (Lieu $l) => $l->nom.($l->ville ? " ({$l->ville->nom})" : ''))->implode(', ');
    }

    public function concorde(PropositionIa $proposition, ElementATraiter $element): ?bool
    {
        return match (true) {
            ! empty($element->decision['confirme']) => $proposition->proposition['decision'] === 'meme_spectacle',
            ! empty($element->decision['separe']) => $proposition->proposition['decision'] === 'separer',
            default => null,
        };
    }
}
