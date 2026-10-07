<?php

namespace App\Actions;

use App\Enums\TypeRepresentation;
use App\Models\Couverture;
use App\Models\Parametre;
use App\Models\Representation;
use App\Models\Ville;
use Carbon\CarbonImmutable;

/**
 * Couverture d'une ville (F7.13, « condition d'existence » §11) : ce que l'app montrerait autour de son centre,
 * dans le rayon du réglage « couverture_rayon_km ». Seules les représentations visibles comptent (Representation::visibles).
 *
 * - ce soir : représentations du jour (une période qui couvre le jour compte) ;
 * - ce week-end : du vendredi au dimanche qui viennent (le week-end en cours à partir du vendredi) ;
 * - 30 jours : représentations des 30 prochains jours, et nombre de spectacles différents ;
 * - avec horaire : part des représentations des 30 jours dont l'heure est connue.
 */
class MesurerCouverture
{
    /** @return array{ce_soir: int, week_end: int, trente_jours: int, spectacles: int, avec_horaire_pct: ?int} */
    public function handle(Ville $ville, ?CarbonImmutable $jour = null): array
    {
        $jour ??= PrioriserBoiteDeTravail::aujourdhui();
        $finWeekEnd = $jour->next(CarbonImmutable::SUNDAY);
        $debutWeekEnd = $jour->dayOfWeekIso >= 5 ? $jour : $jour->next(CarbonImmutable::FRIDAY);
        $finWeekEnd = $jour->isSunday() ? $jour : $finWeekEnd;
        $fin30 = $jour->addDays(29);
        $rayon = (int) Parametre::valeur('couverture_rayon_km') * 1000;

        // Une représentation compte pour [date_locale, date_fin] (une séance : un seul jour).
        $couvre = fn (string $debut, string $fin) => "date_locale <= '{$fin}' and coalesce(date_fin, date_locale) >= '{$debut}'";
        [$j, $wd, $wf, $f30] = [$jour->toDateString(), $debutWeekEnd->toDateString(), $finWeekEnd->toDateString(), $fin30->toDateString()];

        $mesure = Representation::query()
            ->visibles()
            ->whereRaw('ST_DWithin(representations.position, ?::geography, ?)', [$ville->position->versEwkt(), $rayon])
            ->whereRaw($couvre($j, $f30))
            ->toBase()
            ->selectRaw('count(*) filter (where '.$couvre($j, $j).') as ce_soir')
            ->selectRaw('count(*) filter (where '.$couvre($wd, $wf).') as week_end')
            ->selectRaw('count(*) as trente_jours')
            ->selectRaw('count(distinct representations.spectacle_id) as spectacles')
            ->selectRaw('count(*) filter (where representations.type = ?) as avec_horaire', [TypeRepresentation::Seance->value])
            ->first();

        return [
            'ce_soir' => (int) $mesure->ce_soir,
            'week_end' => (int) $mesure->week_end,
            'trente_jours' => (int) $mesure->trente_jours,
            'spectacles' => (int) $mesure->spectacles,
            'avec_horaire_pct' => $mesure->trente_jours > 0 ? (int) round(100 * $mesure->avec_horaire / $mesure->trente_jours) : null,
        ];
    }

    /**
     * Mesure chaque ville pilote et garde la mesure du jour (la dernière de la journée remplace les précédentes).
     * Avec « seulementManquantes » : uniquement les villes pas encore mesurées aujourd'hui (ex. ville pilote ajoutée).
     */
    public function enregistrer(bool $seulementManquantes = false): int
    {
        $jour = PrioriserBoiteDeTravail::aujourdhui();
        $villes = Ville::where('est_pilote', true)
            ->when($seulementManquantes, fn ($q) => $q->whereDoesntHave('couvertures', fn ($c) => $c->whereDate('jour', $jour->toDateString())))
            ->get();

        foreach ($villes as $ville) {
            Couverture::updateOrCreate(
                ['jour' => $jour->toDateString(), 'ville_id' => $ville->id],
                [...$this->handle($ville, $jour), 'mesuree_le' => now()],
            );
        }

        return $villes->count();
    }
}
