<?php

namespace App\Api;

use App\Enums\PrecisionPosition;
use App\Enums\TypeRepresentation;
use App\Models\Parametre;
use App\Models\Representation;
use App\Models\Ville;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * « Autour de moi » (F2, API §3) : les représentations visibles autour d'un point, dans une fenêtre de temps,
 * regroupées en cartes (un spectacle × un lieu × un jour), avec le rayon automatique (F2.4),
 * le bloc « Toute la journée » (F2.2), la couverture faible et la suggestion de date (F2.7).
 */
class AutourDeMoi
{
    public const CARTES_PAR_PAGE = 30;

    public const RAYON_MAX_M = 50_000;

    /** Une ville pilote « proche » : son centre à moins de cette distance (règle du bandeau de couverture, F2.7). */
    public const RAYON_VILLE_PILOTE_M = 30_000;

    /** Fenêtres essayées pour la suggestion de date quand la fenêtre demandée est vide (F2.7). */
    private const SUGGESTIONS = ['ce_soir' => ['demain', 'week_end'], 'demain' => ['week_end'], 'week_end' => []];

    /**
     * @param  array{genres?: list<int>, jeune_public?: bool, rayon?: int|null, tri?: string, page?: int}  $options
     */
    public function handle(Point $centre, string $quand, array $options = []): array
    {
        $fuseau = self::fuseau($centre);
        $fenetre = Fenetre::pour($quand, $fuseau);
        $genres = $options['genres'] ?? [];
        $rayonFixe = $options['rayon'] ?? null;

        // Rayon : compté en base pour chaque palier (F2.4), puis seules les représentations du rayon retenu sont chargées.
        $comptes = $this->compterParPalier($centre, $fenetre, $genres, $options['jeune_public'] ?? false);
        $rayon = $rayonFixe ?? $this->rayonAutomatique($comptes);
        $dansLeRayon = $this->representations($centre, $fenetre, $genres, $options['jeune_public'] ?? false, $rayon);

        [$aHoraire, $sansHoraire] = $dansLeRayon->partition(fn ($l) => $l->type === TypeRepresentation::Seance->value);
        $cartes = $this->trier($this->cartes($aHoraire), $options['tri'] ?? 'heure');
        $page = max(1, (int) ($options['page'] ?? 1));
        $total = $cartes->count() + $sansHoraire->count();
        $a50km = $comptes[self::RAYON_MAX_M];

        return [
            'rayon_retenu_m' => $rayon,
            'couverture_faible' => $this->couvertureFaible($centre, $a50km),
            'suggestion_date' => $total === 0 ? $this->suggestionDate($centre, $quand, $genres, $options['jeune_public'] ?? false, $fuseau) : null,
            'total' => $total,
            'total_tous_genres' => $total === 0 && $genres !== [] ? $this->representations($centre, $fenetre, [], $options['jeune_public'] ?? false, $rayon, compter: true) : null,
            'cartes' => $cartes->forPage($page, self::CARTES_PAR_PAGE)->values(),
            'toute_la_journee' => $page === 1 ? $sansHoraire->sortBy('distance_m')->map(fn ($l) => $this->sansHoraire($l))->values() : [],
            'suivant' => $cartes->count() > $page * self::CARTES_PAR_PAGE ? self::curseur($page + 1) : null,
        ];
    }

    /** Représentations visibles de la fenêtre dans le rayon, avec les filtres. */
    private function requete(Point $centre, Fenetre $fenetre, array $genres, bool $jeunePublic, int $rayon): Builder
    {
        $requete = Representation::query()->visibles()
            ->whereRaw('ST_DWithin(representations.position, ?::geography, ?)', [$centre->versEwkt(), $rayon])
            ->when($genres !== [], fn (Builder $q) => $q->whereIn('representations.genre_id', $genres))
            ->when($jeunePublic, fn (Builder $q) => $q->where('vis_spectacle.jeune_public', true));

        return $fenetre->appliquer($requete);
    }

    /** @return Collection<int, object>|int */
    private function representations(Point $centre, Fenetre $fenetre, array $genres, bool $jeunePublic, int $rayon, bool $compter = false): Collection|int
    {
        $requete = $this->requete($centre, $fenetre, $genres, $jeunePublic, $rayon);

        if ($compter) {
            return $requete->count();
        }

        return $requete->toBase()
            ->select([
                'representations.id', 'representations.type', 'representations.debut', 'representations.fin', 'representations.date_locale', 'representations.date_fin',
                'representations.complet', 'representations.gratuit', 'representations.spectacle_id', 'representations.lieu_id',
                'vis_spectacle.titre', 'vis_spectacle.classification_fine', 'vis_spectacle.jeune_public', 'representations.genre_id',
                'vis_lieu.nom as lieu_nom', 'vis_lieu.precision_position', 'vis_lieu.fuseau_horaire',
            ])
            ->selectRaw('round(ST_Distance(representations.position, ?::geography)) as distance_m', [$centre->versEwkt()])
            ->orderBy('distance_m')
            ->get();
    }

    /** @return array<int, int> palier (m) → nombre de représentations de la fenêtre ; toujours aussi 50 km (couverture). */
    private function compterParPalier(Point $centre, Fenetre $fenetre, array $genres, bool $jeunePublic): array
    {
        // Les distances seules (quelques milliers de nombres), comptées par palier en PHP : enveloppée dans un comptage,
        // la même requête faisait choisir à PostgreSQL un mauvais plan (1 s au lieu de 70 ms pour Paris un week-end).
        $distances = $this->requete($centre, $fenetre, $genres, $jeunePublic, self::RAYON_MAX_M)->toBase()
            ->selectRaw('ST_Distance(representations.position, ?::geography) as d', [$centre->versEwkt()])
            ->pluck('d');

        return collect(self::paliers())->mapWithKeys(fn (int $palier) => [$palier => $distances->filter(fn ($d) => $d <= $palier)->count()])->all();
    }

    /** F2.4 : le plus petit palier (2, 5, 10, 25, 50 km) qui donne au moins 8 représentations. */
    private function rayonAutomatique(array $comptes): int
    {
        $minimum = (int) Parametre::valeur('rayon_auto_min_representations');

        foreach (self::paliers() as $palier) {
            if (($comptes[$palier] ?? 0) >= $minimum) {
                return $palier;
            }
        }

        return self::RAYON_MAX_M;
    }

    /** @return list<int> paliers du réglage en mètres, plafonnés à 50 km, 50 km toujours compris */
    private static function paliers(): array
    {
        $paliers = array_map(fn ($km) => min((int) $km * 1000, self::RAYON_MAX_M), (array) Parametre::valeur('rayon_paliers_km'));

        return array_values(array_unique([...$paliers, self::RAYON_MAX_M]));
    }

    /** Une carte = un spectacle × un lieu × un jour, ses séances regroupées (F2.5). */
    private function cartes(Collection $seances): Collection
    {
        return $seances->groupBy(fn ($l) => "{$l->spectacle_id}-{$l->lieu_id}-{$l->date_locale}")->map(function (Collection $groupe) {
            $groupe = $groupe->sortBy('debut')->values();
            $premiere = $groupe->first();
            $complet = $groupe->every(fn ($l) => $l->complet);
            $gratuit = $groupe->contains(fn ($l) => $l->gratuit);

            return [
                'spectacle' => self::spectacle($premiere),
                'lieu' => self::lieu($premiere),
                'distance_m' => self::distance($premiere->distance_m),
                'date_locale' => $premiere->date_locale,
                'type' => TypeRepresentation::Seance->value,
                'seances' => $groupe->map(fn ($l) => [
                    'representation_id' => $l->id,
                    'debut' => CarbonImmutable::parse($l->debut)->setTimezone($l->fuseau_horaire ?? 'Europe/Paris')->toIso8601String(),
                    'complet' => (bool) $l->complet,
                ])->all(),
                'badges' => array_values(array_filter([$complet ? 'complet' : null, $gratuit ? 'gratuit' : null])),
                'gratuit' => $gratuit,
            ];
        })->values();
    }

    /** Tri par heure de la 1re séance (puis distance) ou par distance ; les complets toujours en fin de liste (F2.5). */
    private function trier(Collection $cartes, string $tri): Collection
    {
        return $cartes->sort(function (array $a, array $b) use ($tri) {
            $complets = in_array('complet', $a['badges'], true) <=> in_array('complet', $b['badges'], true);
            $heure = $a['seances'][0]['debut'] <=> $b['seances'][0]['debut'];
            $distance = $a['distance_m'] <=> $b['distance_m'];

            return $complets ?: ($tri === 'distance' ? ($distance ?: $heure) : ($heure ?: $distance));
        })->values();
    }

    /** Une représentation sans horaire précis (journée, période, continu) : bloc « Toute la journée » (F2.2). */
    private function sansHoraire(object $l): array
    {
        return [
            'spectacle' => self::spectacle($l),
            'lieu' => self::lieu($l),
            'distance_m' => self::distance($l->distance_m),
            'representation_id' => $l->id,
            'type' => $l->type,
            'date_locale' => $l->date_locale,
            'date_fin' => $l->date_fin,
            'fin' => $l->fin ? CarbonImmutable::parse($l->fin)->setTimezone($l->fuseau_horaire ?? 'Europe/Paris')->toIso8601String() : null,
            'badges' => array_values(array_filter([$l->type === TypeRepresentation::Jour->value ? 'horaire_a_confirmer' : null, $l->gratuit ? 'gratuit' : null])),
            'gratuit' => (bool) $l->gratuit,
        ];
    }

    /**
     * F2.7 : bandeau « couverture en cours d'enrichissement » sous 3 représentations à 50 km.
     * Près d'une ville pilote, pas pour un simple soir creux : seulement si la semaine qui vient est vide aussi.
     */
    private function couvertureFaible(Point $centre, int $a50km): bool
    {
        $seuil = (int) Parametre::valeur('seuil_couverture_faible');

        if ($a50km >= $seuil) {
            return false;
        }

        $pilote = Ville::where('est_pilote', true)->whereRaw('ST_DWithin(position, ?::geography, ?)', [$centre->versEwkt(), self::RAYON_VILLE_PILOTE_M])->exists();

        if (! $pilote) {
            return true;
        }

        $semaine = Representation::query()->visibles()
            ->whereRaw('ST_DWithin(representations.position, ?::geography, ?)', [$centre->versEwkt(), self::RAYON_MAX_M])
            ->whereBetween('representations.date_locale', [today()->toDateString(), today()->addDays(6)->toDateString()])
            ->count();

        return $semaine < $seuil;
    }

    /** F2.7 : fenêtre vide → « 4 spectacles ce week-end → Voir » (à 50 km). */
    private function suggestionDate(Point $centre, string $quand, array $genres, bool $jeunePublic, string $fuseau): ?array
    {
        foreach (self::SUGGESTIONS[$quand] ?? [] as $autre) {
            $nombre = $this->representations($centre, Fenetre::pour($autre, $fuseau), $genres, $jeunePublic, self::RAYON_MAX_M, compter: true);

            if ($nombre > 0) {
                return ['quand' => $autre, 'nombre' => $nombre];
            }
        }

        return null;
    }

    /** Fuseau du point de recherche : celui de la commune la plus proche (outre-mer), sinon Paris. */
    public static function fuseau(Point $centre): string
    {
        return Ville::whereRaw('ST_DWithin(position, ?::geography, ?)', [$centre->versEwkt(), self::RAYON_MAX_M])
            ->orderByRaw('position <-> ?::geography', [$centre->versEwkt()])
            ->value('fuseau_horaire') ?? 'Europe/Paris';
    }

    public static function curseur(int $page): string
    {
        return rtrim(strtr(base64_encode(json_encode(['p' => $page])), '+/', '-_'), '=');
    }

    public static function pageDuCurseur(?string $curseur): int
    {
        if ($curseur === null || $curseur === '') {
            return 1;
        }

        $donnees = json_decode((string) base64_decode(strtr($curseur, '-_', '+/')), true);

        return is_array($donnees) && is_int($donnees['p'] ?? null) && $donnees['p'] >= 1 ? $donnees['p'] : 1;
    }

    private static function spectacle(object $l): array
    {
        return ['id' => $l->spectacle_id, 'titre' => $l->titre, 'classification' => $l->classification_fine, 'genre_id' => $l->genre_id, 'jeune_public' => (bool) $l->jeune_public];
    }

    private static function lieu(object $l): array
    {
        return ['id' => $l->lieu_id, 'nom' => $l->lieu_nom, 'position_approximative' => $l->precision_position === PrecisionPosition::Commune->value];
    }

    /** Arrondie à 10 m (l'app affiche « 900 m » ou « 3 km »). */
    private static function distance(float|int|string $metres): int
    {
        return (int) (round((float) $metres / 10) * 10);
    }
}
