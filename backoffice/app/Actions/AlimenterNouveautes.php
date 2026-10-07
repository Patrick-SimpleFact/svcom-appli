<?php

namespace App\Actions;

use App\Enums\TypeRepresentation;
use App\Models\Nouveaute;
use App\Models\Parametre;
use App\Models\Representation;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * File des nouveautés (F3.4, COLLECTE §8) : après chaque publication, pour les représentations créées depuis le début de la collecte.
 * - un lieu suivi annonce un nouveau spectacle (sa 1re représentation dans ce lieu) ;
 * - un artiste suivi a une nouvelle date dans la zone des alertes (une commune et un rayon ; sans zone choisie : partout) ;
 * - chaque matin, rappel du jour J pour la séance choisie d'un favori.
 * Garde-fous : rien de complet, passé ou masqué ; jamais deux fois la même nouveauté (clé unique) ; alertes coupées respectées.
 */
class AlimenterNouveautes
{
    /** @return int nouveautés ajoutées */
    public function apresPublication(CarbonInterface $depuis): int
    {
        $nouvelles = $this->aVenirVisibles()->where('representations.created_at', '>=', $depuis)->where('representations.complet', false)->select('representations.id');
        $colonnes = ['utilisateur_id', 'type', 'cle', 'representation_id', 'spectacle_id', 'suivi_id'];

        // Lieu suivi : un spectacle qui n'avait encore aucune représentation dans ce lieu avant cette collecte.
        $lieux = DB::table('representations as r')
            ->join('suivis as s', fn ($j) => $j->on('s.cible_id', '=', 'r.lieu_id')->where('s.type', 'lieu'))
            ->leftJoin('preferences as p', 'p.utilisateur_id', '=', 's.utilisateur_id')
            ->whereIn('r.id', $nouvelles)
            ->whereRaw('coalesce(p.alertes_actives, true)')
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('representations as avant')
                ->whereColumn('avant.spectacle_id', 'r.spectacle_id')->whereColumn('avant.lieu_id', 'r.lieu_id')->where('avant.created_at', '<', $depuis))
            ->groupBy('s.utilisateur_id', 'r.spectacle_id', 'r.lieu_id', 's.id')
            ->selectRaw("s.utilisateur_id, ?, 's' || r.spectacle_id || '-l' || r.lieu_id, min(r.id), r.spectacle_id, s.id", [Nouveaute::NOUVEAU_SPECTACLE_LIEU]);

        // Artiste suivi : chaque nouvelle date dans la zone des alertes.
        $artistes = DB::table('representations as r')
            ->join('spectacle_artiste as sa', 'sa.spectacle_id', '=', 'r.spectacle_id')
            ->join('suivis as s', fn ($j) => $j->on('s.cible_id', '=', 'sa.artiste_id')->where('s.type', 'artiste'))
            ->leftJoin('preferences as p', 'p.utilisateur_id', '=', 's.utilisateur_id')
            ->leftJoin('villes as zone', 'zone.id', '=', 'p.zone_alertes_ville_id')
            ->whereIn('r.id', $nouvelles)
            ->whereRaw('coalesce(p.alertes_actives, true)')
            ->whereRaw('(zone.id is null or ST_DWithin(r.position, zone.position, coalesce(p.zone_alertes_rayon_km, ?) * 1000))', [(int) Parametre::valeur('zone_alertes_rayon_km')])
            ->selectRaw("distinct s.utilisateur_id, ?, 'r' || r.id, r.id, r.spectacle_id, s.id", [Nouveaute::NOUVELLE_DATE_ARTISTE]);

        return DB::table('nouveautes')->insertOrIgnoreUsing($colonnes, $lieux)
            + DB::table('nouveautes')->insertOrIgnoreUsing($colonnes, $artistes);
    }

    /** Chaque matin : « Ce soir : [spectacle] au [lieu] » pour la séance choisie d'un favori (rappel facultatif, F3.4). */
    public function rappelsDuJour(): int
    {
        $aujourdhui = PrioriserBoiteDeTravail::aujourdhui()->toDateString();
        $visibles = $this->aVenirVisibles()->where('representations.date_locale', $aujourdhui)->select('representations.id');

        $rappels = DB::table('favoris as f')
            ->join('representations as r', 'r.id', '=', 'f.representation_id')
            ->leftJoin('preferences as p', 'p.utilisateur_id', '=', 'f.utilisateur_id')
            ->whereIn('r.id', $visibles)
            ->whereRaw('coalesce(p.rappel_jour_j, true)')
            ->selectRaw("f.utilisateur_id, ?, 'r' || r.id || '-' || ?, r.id, r.spectacle_id, null", [Nouveaute::RAPPEL_JOUR_J, $aujourdhui]);

        return DB::table('nouveautes')->insertOrIgnoreUsing(['utilisateur_id', 'type', 'cle', 'representation_id', 'spectacle_id', 'suivi_id'], $rappels);
    }

    /** Représentations visibles pas encore passées (requête de base). */
    private function aVenirVisibles(): Builder
    {
        return Representation::query()->visibles()
            ->where(fn ($q) => $q
                ->where(fn ($s) => $s->where('representations.type', TypeRepresentation::Seance->value)->where('representations.debut', '>=', now()))
                ->orWhere(fn ($j) => $j->whereNull('representations.debut')->whereRaw('coalesce(representations.date_fin, representations.date_locale) >= ?', [today()->toDateString()])))
            ->toBase();
    }
}
