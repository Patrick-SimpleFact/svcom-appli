<?php

namespace App\Api;

use App\Actions\PrioriserBoiteDeTravail;
use App\Enums\TypeRepresentation;
use App\Models\Lieu;
use App\Models\Recherche as RechercheJournalisee;
use App\Models\Representation;
use App\Models\Spectacle;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recherche complète (F4.3 à F4.5, API §4) : texte facultatif et filtres, cartes (spectacle × lieu × jour) groupées par jour,
 * paginées en base par 30. Le texte trouve des spectacles, des lieux, des villes et des artistes (fautes légères tolérées).
 * Prix inconnu : masqué quand un filtre de prix est actif, et compté (`sans_prix_masques`).
 * Aucun résultat : correction proposée, puis élargissement (50 km, ou 30 jours). Chaque recherche est journalisée sans identifiant.
 */
class Recherche
{
    public const CARTES_PAR_PAGE = 30;

    public const RAYON_ELARGI_M = 50_000;

    public const JOURS_ELARGIS = 30;

    /** Au-delà, le total est « plus de 1 000 » (compter exactement 70 000 cartes coûtait 1 s). */
    public const TOTAL_MAX = 1000;

    /** Fenêtres essayées successivement pour une page triée par date : 7 jours, 30 jours, puis tout l'horizon. */
    private const FENETRES_JOURS = [6, 29, null];

    /** Moment de la journée (F4.3), en heures locales du lieu ; la soirée continue après minuit (jusqu'à 4 h). */
    public const MOMENTS = ['matinee' => [4, 12], 'apres_midi' => [12, 18], 'soiree' => [18, 28]];

    /**
     * @param  array{texte?: ?string, du?: ?string, au?: ?string, centre?: ?Point, rayon?: ?int, ville_id?: ?int, genres?: list<int>,
     *     jeune_public?: bool, moment?: ?string, prix_max?: int|float|null, masquer_complets?: bool, tri?: string, page?: int}  $f
     */
    public function handle(array $f): array
    {
        $texte = TexteCherche::preparer($f['texte'] ?? null);
        $page = max(1, (int) ($f['page'] ?? 1));

        $total = $this->compter($this->requete($f, $texte), $f);
        [$cartes, $encore] = $this->page($f, $texte, $page);

        $resultat = [
            'total' => min($total, self::TOTAL_MAX),
            'total_plus' => $total > self::TOTAL_MAX,
            'jours' => $cartes->groupBy('date_locale')->map(fn (Collection $c, string $jour) => ['date' => $jour, 'cartes' => $c->values()->all()])->values()->all(),
            'sans_prix_masques' => isset($f['prix_max']) ? $this->sansPrix($f, $texte) : 0,
            // Correction seulement si le texte ne correspond à rien nulle part (pas si c'est le lieu ou la date qui ne donne rien).
            'correction' => $total === 0 && $texte !== null && array_merge(...$this->correspondances($texte)) === [] ? $this->correction($texte) : null,
            'elargir' => $total === 0 ? $this->elargir($f, $texte) : null,
            'suivant' => $encore ? AutourDeMoi::curseur($page + 1) : null,
        ];

        if ($page === 1 && ($f['journaliser'] ?? true)) {
            RechercheJournalisee::create([
                'texte' => mb_substr(trim((string) ($f['texte'] ?? '')), 0, 200) ?: null,
                'ville_id' => $f['ville_id'] ?? null,
                'nb_resultats' => $total,
                'filtres' => array_filter(collect($f)->except(['texte', 'centre', 'page', 'ville_id', 'journaliser'])->all(), fn ($v) => $v !== null && $v !== [] && $v !== false) ?: null,
            ]);
        }

        return $resultat;
    }

    /** Représentations visibles à venir qui répondent au texte et aux filtres. */
    private function requete(array $f, ?string $texte, bool $avecPrix = true): Builder
    {
        $aujourdhui = PrioriserBoiteDeTravail::aujourdhui()->toDateString();
        $du = max($f['du'] ?? $aujourdhui, $aujourdhui);
        $centre = $f['centre'] ?? null;

        $requete = Representation::query()->visibles()
            ->where(fn (Builder $q) => $q
                ->where(fn ($s) => $s->where('representations.type', TypeRepresentation::Seance->value)->where('representations.debut', '>=', now()))
                ->orWhere(fn ($j) => $j->whereNull('representations.debut')->whereRaw('coalesce(representations.date_fin, representations.date_locale) >= ?', [$aujourdhui])))
            ->whereRaw('coalesce(representations.date_fin, representations.date_locale) >= ?', [$du])
            ->when($f['au'] ?? null, fn (Builder $q, string $au) => $q->where('representations.date_locale', '<=', $au))
            ->when($centre && ($f['rayon'] ?? null), fn (Builder $q) => $q->whereRaw('ST_DWithin(representations.position, ?::geography, ?)', [$centre->versEwkt(), $f['rayon']]))
            ->when($centre === null && ($f['ville_id'] ?? null), fn (Builder $q) => $q->where('representations.ville_id', $f['ville_id']))
            ->when(($f['genres'] ?? []) !== [], fn (Builder $q) => $q->whereIn('representations.genre_id', $f['genres']))
            // Pages lieu et artiste (P04) : les dates à venir d'un lieu, ou de spectacles donnés.
            ->when($f['lieu_id'] ?? null, fn (Builder $q, int $lieu) => $q->where('representations.lieu_id', $lieu))
            ->when(array_key_exists('spectacle_ids', $f), fn (Builder $q) => $q->whereIn('representations.spectacle_id', $f['spectacle_ids']))
            ->when($f['jeune_public'] ?? false, fn (Builder $q) => $q->where('vis_spectacle.jeune_public', true))
            ->when($f['masquer_complets'] ?? false, fn (Builder $q) => $q->where('representations.complet', false))
            ->when($f['moment'] ?? null, function (Builder $q, string $moment) {
                [$de, $a] = self::MOMENTS[$moment];
                $heure = '(extract(hour from representations.debut at time zone vis_lieu.fuseau_horaire)::int + case when extract(hour from representations.debut at time zone vis_lieu.fuseau_horaire) < 4 then 24 else 0 end)';

                return $q->where('representations.type', TypeRepresentation::Seance->value)->whereRaw("{$heure} >= ? and {$heure} < ?", [$de, $a]);
            });

        if ($avecPrix && isset($f['prix_max'])) {
            // 0 = gratuit ; sinon « moins de N € » ; un prix inconnu ne passe pas (F4.3).
            $requete->where(fn (Builder $q) => (float) $f['prix_max'] <= 0
                ? $q->where('representations.gratuit', true)->orWhere('representations.prix_min', 0)
                : $q->where('representations.gratuit', true)->orWhere('representations.prix_min', '<=', $f['prix_max']));
        }

        if ($texte !== null) {
            // D'abord ce qui correspond au texte, par les index par trigrammes de chaque table ; puis leurs représentations.
            [$spectacles, $lieux, $villes] = $this->correspondances($texte);
            $requete->where(fn (Builder $q) => $q
                ->whereIn('representations.spectacle_id', $spectacles)
                ->orWhereIn('representations.lieu_id', $lieux)
                ->orWhereIn('representations.ville_id', $villes));
        }

        return $requete;
    }

    /** @return array{list<int>, list<int>, list<int>} spectacles (par leur titre ou un artiste), lieux, villes qui répondent au texte */
    private function correspondances(string $texte): array
    {
        return $this->correspondancesCalculees[$texte] ??= [
            DB::table('spectacles')->where('masque', false)->where(fn ($q) => TexteCherche::correspond($q, 'titre_normalise', $texte))->pluck('id')
                ->merge(DB::table('spectacle_artiste as sa')->join('artistes as a', 'a.id', '=', 'sa.artiste_id')->where(fn ($q) => TexteCherche::correspond($q, 'a.nom_normalise', $texte))->pluck('sa.spectacle_id'))
                ->unique()->values()->all(),
            DB::table('lieux')->where('masque', false)->where(fn ($q) => TexteCherche::correspond($q, 'nom_normalise', $texte))->pluck('id')->all(),
            DB::table('villes')->where('nom_normalise', $texte)->orWhere('nom_normalise', 'like', $texte.' %')->pluck('id')->all(),
        ];
    }

    /** @var array<string, array> */
    private array $correspondancesCalculees = [];

    /** Une page de cartes, groupées et triées en base. */
    private function cartes(Builder $requete, array $f, int $page, int $parPage = self::CARTES_PAR_PAGE): Collection
    {
        $centre = $f['centre'] ?? null;
        $distance = $centre ? DB::raw('min(ST_Distance(representations.position, \''.$centre->versEwkt().'\'::geography)) as distance_m') : DB::raw('null as distance_m');

        // Une période commencée avant la fenêtre se range à son premier jour dans la fenêtre (pas en 2016 !).
        $du = max($f['du'] ?? PrioriserBoiteDeTravail::aujourdhui()->toDateString(), PrioriserBoiteDeTravail::aujourdhui()->toDateString());
        $jour = DB::raw("greatest(representations.date_locale, '{$du}'::date)");

        $groupes = $requete->toBase()
            ->select(['representations.spectacle_id', 'representations.lieu_id', DB::raw("greatest(representations.date_locale, '{$du}'::date) as date_locale"), $distance])
            ->selectRaw('min(representations.type) as type, max(representations.date_fin) as date_fin, min(representations.debut) as premier')
            ->selectRaw('bool_and(representations.complet) as complet, bool_or(representations.gratuit) as gratuit, min(representations.prix_min) as prix_min')
            ->selectRaw("json_agg(json_build_object('representation_id', representations.id, 'debut', representations.debut, 'complet', representations.complet) order by representations.debut) as seances")
            ->groupBy('representations.spectacle_id', 'representations.lieu_id', $jour)
            ->when(($f['tri'] ?? 'date') === 'distance' && $centre, fn ($q) => $q->orderBy('distance_m')->orderBy($jour))
            ->when(($f['tri'] ?? 'date') === 'prix', fn ($q) => $q->orderByRaw('case when bool_or(representations.gratuit) then 0 else min(representations.prix_min) end asc nulls last')->orderBy($jour)) // gratuit = 0 €
            ->orderBy($jour)
            ->orderByRaw('bool_and(representations.complet)')
            ->orderByRaw('min(representations.debut) asc nulls last')
            ->orderBy('representations.spectacle_id')
            ->forPage($page, $parPage)
            ->get();

        $spectacles = Spectacle::whereIn('id', $groupes->pluck('spectacle_id'))->get(['id', 'titre', 'classification_fine', 'genre_id', 'jeune_public'])->keyBy('id');
        $lieux = Lieu::with('ville:id,nom')->whereIn('id', $groupes->pluck('lieu_id'))->get(['id', 'nom', 'precision_position', 'fuseau_horaire', 'ville_id'])->keyBy('id');

        return $groupes->map(function ($g) use ($spectacles, $lieux) {
            $s = $spectacles[$g->spectacle_id];
            $l = $lieux[$g->lieu_id];
            $fuseau = $l->fuseau_horaire ?? 'Europe/Paris';
            $sansHoraire = $g->type !== TypeRepresentation::Seance->value;

            return [
                'spectacle' => ['id' => $s->id, 'titre' => $s->titre, 'classification' => $s->classification_fine, 'genre_id' => $s->genre_id, 'jeune_public' => $s->jeune_public],
                'lieu' => ['id' => $l->id, 'nom' => $l->nom, 'ville' => $l->ville?->nom, 'position_approximative' => $l->precision_position?->value === 'commune'],
                'distance_m' => $g->distance_m === null ? null : (int) (round((float) $g->distance_m / 10) * 10),
                'date_locale' => $g->date_locale,
                'date_fin' => $g->date_fin,
                'type' => $g->type,
                'seances' => $sansHoraire ? [] : collect(json_decode($g->seances, true))->map(fn ($x) => [
                    'representation_id' => $x['representation_id'],
                    'debut' => CarbonImmutable::parse($x['debut'])->setTimezone($fuseau)->toIso8601String(),
                    'complet' => (bool) $x['complet'],
                ])->all(),
                'representation_id' => $sansHoraire ? json_decode($g->seances, true)[0]['representation_id'] : null,
                'prix_min' => $g->prix_min === null ? null : (float) $g->prix_min,
                'badges' => array_values(array_filter([
                    $g->complet ? 'complet' : null, $g->gratuit ? 'gratuit' : null,
                    $g->type === TypeRepresentation::Jour->value ? 'horaire_a_confirmer' : null,
                ])),
                'gratuit' => (bool) $g->gratuit,
            ];
        });
    }

    /** Nombre de cartes (spectacle × lieu × jour, une période comptant au premier jour cherché), exact jusqu'à TOTAL_MAX. */
    private function compter(Builder $requete, array $f): int
    {
        $du = max($f['du'] ?? PrioriserBoiteDeTravail::aujourdhui()->toDateString(), PrioriserBoiteDeTravail::aujourdhui()->toDateString());
        $cle = "(representations.spectacle_id, representations.lieu_id, greatest(representations.date_locale, '{$du}'::date))";

        // Au-delà de TOTAL_MAX représentations, il y a forcément beaucoup de cartes : on s'arrête là.
        $lignes = DB::query()->fromSub((clone $requete)->toBase()->select('representations.id')->limit(self::TOTAL_MAX * 3 + 1), 'x')->count();
        if ($lignes > self::TOTAL_MAX * 3) {
            return self::TOTAL_MAX + 1;
        }

        return (clone $requete)->distinct()->count(DB::raw($cle));
    }

    /**
     * Une page de cartes, et s'il en reste après. Triée par date : on cherche d'abord dans les 7 prochains jours,
     * puis 30, puis tout l'horizon, seulement s'il manque des cartes (la 1re page vient presque toujours de la 1re semaine).
     *
     * @return array{Collection, bool}
     */
    private function page(array $f, ?string $texte, int $page): array
    {
        $besoin = $page * self::CARTES_PAR_PAGE + 1;
        $du = CarbonImmutable::parse(max($f['du'] ?? PrioriserBoiteDeTravail::aujourdhui()->toDateString(), PrioriserBoiteDeTravail::aujourdhui()->toDateString()));
        $fenetres = ($f['tri'] ?? 'date') === 'date' ? self::FENETRES_JOURS : [null];

        foreach ($fenetres as $jours) {
            $au = $jours === null ? ($f['au'] ?? null) : min($du->addDays($jours)->toDateString(), $f['au'] ?? '9999-12-31');
            $cartes = $this->cartes($this->requete([...$f, 'au' => $au], $texte), $f, 1, $besoin);

            if ($cartes->count() >= $besoin || $jours === null || $au === ($f['au'] ?? null)) {
                return [$cartes->slice(($page - 1) * self::CARTES_PAR_PAGE, self::CARTES_PAR_PAGE)->values(), $cartes->count() >= $besoin];
            }
        }

        return [collect(), false];
    }

    /** F4.3 : spectacles cachés par le filtre de prix faute de prix connu. */
    private function sansPrix(array $f, ?string $texte): int
    {
        return $this->requete($f, $texte, avecPrix: false)
            ->whereNull('representations.prix_min')->where('representations.gratuit', false)
            ->distinct()->count('representations.spectacle_id');
    }

    /**
     * F4.5 : « Vouliez-vous dire Observance ? » — le lieu, la ville ou le spectacle à venir le plus proche du texte
     * (strict_word_similarity : un mot entier proche, pas un bout de mot ; « zzzzqqq » ne propose rien).
     */
    public const SEUIL_CORRECTION = 0.45;

    private function correction(string $texte): ?string
    {
        $aVenir = DB::table('representations')->where('statut', 'programmee')->whereRaw('coalesce(date_fin, date_locale) >= current_date')->select('spectacle_id');
        $candidats = DB::table('lieux')->where('masque', false)->whereNull('fusionne_dans_id')->select(['nom as texte', 'nom_normalise as norme'])
            ->unionAll(DB::table('villes')->select(['nom as texte', 'nom_normalise as norme']))
            ->unionAll(DB::table('spectacles')->where('masque', false)->whereIn('id', $aVenir)->select(['titre as texte', 'titre_normalise as norme']));

        $meilleur = DB::query()->fromSub($candidats, 'c')
            ->whereRaw('strict_word_similarity(?, norme) >= ?', [$texte, self::SEUIL_CORRECTION])
            ->orderByRaw('strict_word_similarity(?, norme) desc', [$texte])
            ->orderByRaw('length(texte)')
            ->value('texte');

        return $meilleur !== null && TexteCherche::preparer($meilleur) !== $texte ? $meilleur : null;
    }

    /** F4.5 : rien ici → « Voir dans un rayon de 50 km », sinon « … sur les 30 prochains jours ». */
    private function elargir(array $f, ?string $texte): ?array
    {
        if (($f['centre'] ?? null) && ($f['rayon'] ?? self::RAYON_ELARGI_M) < self::RAYON_ELARGI_M) {
            $nombre = $this->compter($this->requete([...$f, 'rayon' => self::RAYON_ELARGI_M], $texte), $f);

            if ($nombre > 0) {
                return ['rayon_m' => self::RAYON_ELARGI_M, 'total' => $nombre];
            }
        }

        $du = CarbonImmutable::parse(max($f['du'] ?? PrioriserBoiteDeTravail::aujourdhui()->toDateString(), PrioriserBoiteDeTravail::aujourdhui()->toDateString()));

        if (($f['au'] ?? null) !== null && CarbonImmutable::parse($f['au'])->lessThan($du->addDays(self::JOURS_ELARGIS))) {
            $au = $du->addDays(self::JOURS_ELARGIS)->toDateString();
            $nombre = $this->compter($this->requete([...$f, 'au' => $au], $texte), $f);

            if ($nombre > 0) {
                return ['au' => $au, 'total' => $nombre];
            }
        }

        return null;
    }
}
