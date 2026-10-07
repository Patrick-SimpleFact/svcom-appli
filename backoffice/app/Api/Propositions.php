<?php

namespace App\Api;

use App\Enums\StatutRepresentation;
use App\Models\Representation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Propositions pendant la frappe (F4.1, API §4) : 3 au plus par type (spectacles, artistes, lieux, villes), en moins de 300 ms.
 * Tolérance aux fautes : « mot proche » de pg_trgm (`texte <% colonne`, index par trigrammes), puis classement :
 * ce qui commence par le texte, puis un mot qui commence par le texte, puis la ressemblance.
 * Spectacles et artistes : seulement s'ils ont des dates à venir visibles ; lieux à venir d'abord.
 */
class Propositions
{
    public const PAR_TYPE = 3;

    /** @return array{spectacles: list<array>, artistes: list<array>, lieux: list<array>, villes: list<array>} */
    public function handle(string $texte): array
    {
        return [
            'spectacles' => $this->spectacles($texte),
            'artistes' => $this->artistes($texte),
            'lieux' => $this->lieux($texte),
            'villes' => $this->villes($texte),
        ];
    }

    private function spectacles(string $texte): array
    {
        $trouves = $this->classer(DB::table('spectacles as s'), 's.titre_normalise', $texte)
            ->where('s.masque', false)
            ->whereIn('s.id', $this->aVenir()->select('representations.spectacle_id'))
            ->limit(self::PAR_TYPE)
            ->get(['s.id', 's.titre']);

        return $trouves->map(function ($s) {
            $lieux = $this->aVenir()->where('representations.spectacle_id', $s->id)
                ->join('villes as v', 'v.id', '=', 'representations.ville_id', 'left')
                ->selectRaw('vis_lieu.nom as lieu, v.nom as ville, min(representations.date_locale) as prochaine')
                ->groupBy('vis_lieu.nom', 'v.nom')->orderBy('prochaine')->get();
            $premier = $lieux->first();
            $sousTitre = match (true) {
                $lieux->count() > 1 => $lieux->pluck('ville')->filter()->unique()->count() > 1
                    ? $lieux->pluck('ville')->filter()->unique()->count().' villes'
                    : $lieux->count().' lieux, '.$premier->ville,
                $premier !== null => collect([$premier->lieu, $premier->ville])->filter()->implode(', '),
                default => null,
            };

            return ['id' => $s->id, 'titre' => $s->titre, 'sous_titre' => $sousTitre];
        })->all();
    }

    private function artistes(string $texte): array
    {
        $dates = $this->aVenir()->join('spectacle_artiste as sa', 'sa.spectacle_id', '=', 'representations.spectacle_id')
            ->whereColumn('sa.artiste_id', 'a.id')->selectRaw('count(*)');

        return $this->classer(DB::table('artistes as a'), 'a.nom_normalise', $texte)
            ->whereNull('a.fusionne_dans_id')
            ->select(['a.id', 'a.nom'])->selectSub($dates, 'dates')
            ->get()
            ->filter(fn ($a) => $a->dates > 0)
            ->take(self::PAR_TYPE)
            ->map(fn ($a) => ['id' => $a->id, 'nom' => $a->nom, 'sous_titre' => $a->dates.' date'.($a->dates > 1 ? 's' : '').' à venir'])
            ->values()->all();
    }

    private function lieux(string $texte): array
    {
        $aVenir = $this->aVenir()->whereColumn('representations.lieu_id', 'l.id')->selectRaw('1');

        return $this->classer(DB::table('lieux as l'), 'l.nom_normalise', $texte, prioriteSql: 'case when exists ('.$aVenir->toSql().') then 0 else 1 end', prioriteBindings: $aVenir->getBindings())
            ->whereNull('l.fusionne_dans_id')->where('l.masque', false)
            ->leftJoin('villes as v', 'v.id', '=', 'l.ville_id')
            ->limit(self::PAR_TYPE)
            ->get(['l.id', 'l.nom', 'v.nom as ville'])
            ->map(fn ($l) => ['id' => $l->id, 'nom' => $l->nom, 'sous_titre' => $l->ville])
            ->all();
    }

    private function villes(string $texte): array
    {
        // Les villes : le début du nom compte d'abord (« Avign »), puis les plus peuplées.
        return DB::table('villes')
            ->where(fn (Builder $q) => $q->where('nom_normalise', 'like', $texte.'%')->orWhereRaw('? <% nom_normalise', [$texte]))
            ->orderByRaw('case when nom_normalise like ? then 0 else 1 end', [$texte.'%'])
            ->orderByDesc('est_pilote')
            ->orderByDesc('population')
            ->limit(self::PAR_TYPE)
            ->get(['id', 'nom', 'departement'])
            ->map(fn ($v) => ['id' => $v->id, 'nom' => $v->nom, 'sous_titre' => "({$v->departement})"])
            ->all();
    }

    /** Correspondance tolérante, et ordre : début du nom, début d'un mot, priorité éventuelle, ressemblance, nom le plus court. */
    private function classer(Builder $requete, string $colonne, string $texte, ?string $prioriteSql = null, array $prioriteBindings = []): Builder
    {
        return $requete
            ->where(fn (Builder $q) => $q->where($colonne, 'like', '%'.$texte.'%')->orWhere(fn (Builder $m) => TexteCherche::correspond($m, $colonne, $texte)))
            ->orderByRaw("case when {$colonne} like ? then 0 when {$colonne} like ? then 1 else 2 end", [$texte.'%', '% '.$texte.'%'])
            ->when($prioriteSql, fn (Builder $q) => $q->orderByRaw($prioriteSql, $prioriteBindings))
            ->orderByRaw("word_similarity(?, {$colonne}) desc", [$texte])
            ->orderByRaw("length({$colonne})");
    }

    /** Représentations visibles à venir (requête de base, sans colonnes). */
    private function aVenir(): Builder
    {
        return Representation::query()->visibles()
            ->where('representations.statut', StatutRepresentation::Programmee)
            ->where(fn ($q) => $q->where('representations.debut', '>=', now())
                ->orWhere(fn ($j) => $j->whereNull('representations.debut')->whereRaw('coalesce(representations.date_fin, representations.date_locale) >= ?', [today()->toDateString()])))
            ->toBase();
    }
}
