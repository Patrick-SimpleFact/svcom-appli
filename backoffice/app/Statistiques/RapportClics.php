<?php

namespace App\Statistiques;

use App\Models\Lieu;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Rapport de trafic pour une billetterie ou un lieu (F7.13 bis) : seulement des chiffres agrégés, jamais un clic ni un appareil.
 * Une ligne de moins de 10 clics (lieu, spectacle, ville, genre…) est regroupée dans « Autres », pour que personne ne soit reconnaissable.
 * Ne compte que les clics retenus (un même appareil, même billetterie, même séance en 30 min = 1 ; robots et tests exclus).
 */
class RapportClics
{
    public const SEUIL_REGROUPEMENT = 10;

    private const ORIGINES = [
        'liste_ce_soir' => 'Liste « autour de moi »', 'recherche' => 'Recherche', 'suggestion_auto' => 'Suggestion à l’ouverture',
        'suggestion_sponsorisee' => 'Suggestion sponsorisée', 'favori' => 'Favoris', 'lien_partage' => 'Lien partagé',
        'page_lieu' => 'Page d’un lieu', 'page_artiste' => 'Page d’un artiste', 'fiche' => 'Fiche spectacle',
    ];

    /**
     * @param  'billetterie'|'lieu'  $type
     * @return array{titre: string, sous_titre: string, du: CarbonImmutable, au: CarbonImmutable, total: int, faits: list<string>, sections: array<string, list<array{libelle: string, clics: int, part: float}>>, par_jour: array<string, int>}
     */
    public function calculer(string $type, int $id, CarbonImmutable $du, CarbonImmutable $au): array
    {
        $du = $du->setTimezone('Europe/Paris')->startOfDay();
        $au = $au->setTimezone('Europe/Paris')->endOfDay();
        $cible = $type === 'billetterie' ? Source::findOrFail($id) : Lieu::with('ville')->findOrFail($id);

        $base = fn (): Builder => DB::table('clics_sortants as c')->where('c.compte', true)
            ->whereBetween('c.horodatage', [$du->utc(), $au->utc()])
            ->where($type === 'billetterie' ? 'c.source_id' : 'c.lieu_id', $id);

        $total = $base()->count();
        $parNom = fn (string $table, string $colonne, string $champ = 'nom') => $base()->leftJoin("{$table} as t", 't.id', '=', "c.{$colonne}")
            ->groupBy("t.{$champ}")->selectRaw("coalesce(t.{$champ}, 'Inconnu') as libelle, count(*) as clics")->orderByDesc('clics')->get();

        $sections = [
            'Par ville' => $this->regrouper($parNom('villes', 'ville_id'), $total),
            $type === 'billetterie' ? 'Par lieu' : 'Par billetterie' => $this->regrouper($type === 'billetterie' ? $parNom('lieux', 'lieu_id') : $parNom('sources', 'source_id'), $total),
            'Par spectacle' => $this->regrouper($parNom('spectacles', 'spectacle_id', 'titre'), $total),
            'Par genre' => $this->regrouper($parNom('genres', 'genre_id', 'libelle'), $total),
            'D’où vient le visiteur' => $this->regrouper($base()->groupBy('c.origine')->selectRaw('c.origine as libelle, count(*) as clics')->orderByDesc('clics')->get()
                ->map(fn ($l) => (object) ['libelle' => self::ORIGINES[$l->libelle] ?? 'Non précisé', 'clics' => $l->clics]), $total),
            'Moment de la journée' => $this->tranches($base(), "extract(hour from c.horodatage at time zone 'Europe/Paris')", [
                [0, 12, 'Matin (avant midi)'], [12, 18, 'Après-midi (12 h – 18 h)'], [18, 24, 'Soirée (après 18 h)'],
            ], $total),
            'Délai avant la séance' => $this->tranches($base(), 'c.delai_avant_seance_min', [
                [PHP_INT_MIN, 0, 'Après le début'], [0, 60, 'Moins d’une heure'], [60, 180, '1 à 3 heures'], [180, 1440, '3 heures à 1 jour'],
                [1440, 10080, '1 à 7 jours'], [10080, PHP_INT_MAX, 'Plus de 7 jours'],
            ], $total),
            'Bouton' => $this->ajouterParts($base()->groupBy('c.bouton')->selectRaw("case when c.bouton = 'autre' then 'Choisie dans « Autres billetteries »' else 'Billetterie recommandée' end as libelle, count(*) as clics")->orderByDesc('clics')->get(), $total),
        ];

        $moins3h = $base()->whereBetween('c.delai_avant_seance_min', [0, 179])->count();
        $suggestions = $base()->whereIn('c.origine', ['suggestion_auto', 'suggestion_sponsorisee'])->count();

        return [
            'titre' => $cible->nom,
            'sous_titre' => $type === 'billetterie' ? 'Trafic envoyé par Spettacoli vers la billetterie' : 'Trafic envoyé par Spettacoli pour les spectacles du lieu'.($cible->ville ? " ({$cible->ville->nom})" : ''),
            'du' => $du, 'au' => $au, 'total' => $total,
            'faits' => $total === 0 ? [] : array_values(array_filter([
                "{$total} clic(s) vers la billetterie sur la période, ".number_format($total / $this->jours($du, $au), 1, ',', ' ').' par jour en moyenne.',
                round(100 * $moins3h / $total).' % des clics ont lieu moins de 3 heures avant le spectacle.',
                $suggestions > 0 ? round(100 * $suggestions / $total).' % viennent des suggestions à l’ouverture.' : null,
            ])),
            'sections' => $sections,
            'par_jour' => $base()->selectRaw("(c.horodatage at time zone 'Europe/Paris')::date as jour, count(*) as clics")->groupBy('jour')->orderBy('jour')->pluck('clics', 'jour')->map(fn ($n) => (int) $n)->all(),
        ];
    }

    /** Nombre de jours de la période, bornes comprises. */
    public function jours(CarbonImmutable $du, CarbonImmutable $au): int
    {
        return (int) $du->startOfDay()->diffInDays($au->startOfDay()) + 1;
    }

    /** Lignes sous le seuil regroupées dans « Autres » (F7.13 bis, confidentialité). */
    private function regrouper($lignes, int $total): array
    {
        [$garder, $petits] = collect($lignes)->partition(fn ($l) => (int) $l->clics >= self::SEUIL_REGROUPEMENT);
        $resultat = $garder->map(fn ($l) => (object) ['libelle' => (string) $l->libelle, 'clics' => (int) $l->clics])->values();

        if ($petits->isNotEmpty()) {
            $resultat->push((object) ['libelle' => 'Autres ('.$petits->count().')', 'clics' => (int) $petits->sum('clics')]);
        }

        return $this->ajouterParts($resultat, $total);
    }

    private function tranches(Builder $requete, string $expression, array $tranches, int $total): array
    {
        $cas = collect($tranches)->map(fn ($t, $i) => 'when '.$expression.' >= '.max($t[0], -2147483648).' and '.$expression.' < '.min($t[1], 2147483647)." then {$i}")->implode(' ');
        $comptes = $requete->selectRaw("case {$cas} else -1 end as t, count(*) as clics")->groupBy('t')->pluck('clics', 't');

        return $this->ajouterParts(collect($tranches)->map(fn ($t, $i) => (object) ['libelle' => $t[2], 'clics' => (int) ($comptes[$i] ?? 0)])
            ->push((object) ['libelle' => 'Inconnu', 'clics' => (int) ($comptes[-1] ?? 0)])
            ->filter(fn ($l) => $l->clics > 0)->values(), $total);
    }

    private function ajouterParts($lignes, int $total): array
    {
        return collect($lignes)->map(fn ($l) => ['libelle' => (string) $l->libelle, 'clics' => (int) $l->clics, 'part' => $total > 0 ? round(100 * $l->clics / $total, 1) : 0.0])->values()->all();
    }
}
