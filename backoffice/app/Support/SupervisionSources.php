<?php

namespace App\Support;

use App\Enums\PrecisionPosition;
use App\Enums\StatutCollecte;
use App\Models\Collecte;
use App\Models\Genre;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Chiffres du tableau de supervision des sources (F7.9) : dernière collecte, volumes, variation, qualité.
 * La qualité (sur toutes les offres à venir) est gardée 10 minutes en cache.
 */
class SupervisionSources
{
    public const CACHE_QUALITE = 'supervision.qualite';

    /** Dernière collecte terminée ou en cours. */
    public static function derniereCollecte(int $sourceId): ?Collecte
    {
        return Collecte::where('source_id', $sourceId)->latest('id')->first();
    }

    /** @return array{collecte: ?Collecte, variation_pct: ?int} dernière collecte réussie et écart de ses annonces reçues avec la moyenne des 7 jours précédents */
    public static function volumes(int $sourceId): array
    {
        $derniere = Collecte::where('source_id', $sourceId)->where('statut', StatutCollecte::Reussie)->latest('fin')->first();

        if ($derniere === null) {
            return ['collecte' => null, 'variation_pct' => null];
        }

        $moyenne = Collecte::where('source_id', $sourceId)->where('statut', StatutCollecte::Reussie)
            ->whereKeyNot($derniere->id)
            ->where('fin', '>=', $derniere->fin->subDays(7))->where('fin', '<', $derniere->fin)
            ->avg('nb_recus');

        return [
            'collecte' => $derniere,
            'variation_pct' => $moyenne > 0 ? (int) round(100 * ($derniere->nb_recus - $moyenne) / $moyenne) : null,
        ];
    }

    /** @return array{offres: int, horaire: int, geoloc: int, genre: int}|null pourcentages sur les offres à venir encore vendues */
    public static function qualite(int $sourceId): ?array
    {
        $toutes = Cache::remember(self::CACHE_QUALITE, now()->addMinutes(10), function (): array {
            $autres = Genre::where('slug', 'autres')->value('id');

            return collect(DB::select(<<<'SQL'
                select o.source_id, count(*) as offres,
                       round(100 * avg(o.heure_connue::int)) as horaire,
                       round(100 * avg((l.precision_position in (?, ?))::int)) as geoloc,
                       round(100 * avg((o.genre_id is not null and o.genre_id is distinct from ?)::int)) as genre
                from offres o left join lieux l on l.id = o.lieu_id
                where o.disparue_le is null and coalesce(o.date_fin, o.date_locale) >= current_date
                group by o.source_id
                SQL, [PrecisionPosition::Exacte->value, PrecisionPosition::Adresse->value, $autres]))
                ->mapWithKeys(fn ($l) => [$l->source_id => ['offres' => (int) $l->offres, 'horaire' => (int) $l->horaire, 'geoloc' => (int) $l->geoloc, 'genre' => (int) $l->genre]])
                ->all();
        });

        return $toutes[$sourceId] ?? null;
    }
}
