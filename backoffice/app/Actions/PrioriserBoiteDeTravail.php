<?php

namespace App\Actions;

use App\Enums\FileATraiter;
use App\Enums\StatutElement;
use App\Enums\StatutRepresentation;
use App\Models\Representation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ordre d'urgence de la boîte de travail (F7.10) : les spectacles de ce soir et des villes pilotes d'abord.
 *
 * Pour chaque élément en attente : l'échéance (prochain jour de séance concerné, aujourd'hui au plus tôt),
 * le fait de toucher une ville pilote, puis l'urgence :
 * - 3 : ce soir, dans une ville pilote ;
 * - 2 : ce soir ailleurs, ou dans les 7 jours dans une ville pilote ;
 * - 1 : une ville pilote plus tard, ou dans les 7 jours ailleurs ;
 * - 0 : le reste.
 * File « À classer » (catégorie sans genre, pas de date) : urgence 1 si la catégorie touche au moins 10 annonces.
 * Recalculé toutes les 30 minutes (l'échéance avance avec les jours) et à l'ouverture de la boîte.
 */
class PrioriserBoiteDeTravail
{
    public const JOURS_PROCHES = 7;

    public const ANNONCES_A_CLASSER_URGENTES = 10;

    /** Jour de « ce soir » : une séance avant 4 h compte pour la soirée de la veille (F2.2). */
    public static function aujourdhui(): CarbonImmutable
    {
        return CarbonImmutable::now('Europe/Paris')->subHours(Representation::HEURE_FIN_DE_SOIREE)->startOfDay();
    }

    public function handle(): int
    {
        $aujourdhui = self::aujourdhui()->toDateString();
        $proche = self::aujourdhui()->addDays(self::JOURS_PROCHES)->toDateString();
        $enAttente = StatutElement::EnAttente->value;
        $programmee = StatutRepresentation::Programmee->value;

        // Prochain jour couvert par une date ou une période (date_locale → date_fin), aujourd'hui au plus tôt.
        $prochainJour = fn (string $alias) => "case when coalesce({$alias}.date_fin, {$alias}.date_locale) >= :j then greatest({$alias}.date_locale, :j) end";

        return DB::transaction(function () use ($aujourdhui, $proche, $enAttente, $programmee, $prochainJour) {
            // Doublons et fusions : la séance de la nouvelle annonce (à défaut, de l'annonce déjà connue).
            DB::update(<<<SQL
                update elements_a_traiter e set
                    echeance = coalesce({$prochainJour('ob')}, {$prochainJour('oa')}),
                    ville_pilote = coalesce(vb.est_pilote, va.est_pilote, false)
                from elements_a_traiter x
                left join offres oa on oa.id = (x.donnees->>'offre_a_id')::bigint
                left join offres ob on ob.id = (x.donnees->>'offre_b_id')::bigint
                left join lieux la on la.id = oa.lieu_id left join villes va on va.id = la.ville_id
                left join lieux lb on lb.id = ob.lieu_id left join villes vb on vb.id = lb.ville_id
                where e.id = x.id and x.statut = :s and x.file in (:f1, :f2)
                SQL, ['j' => $aujourdhui, 's' => $enAttente, 'f1' => FileATraiter::DoublonProbable->value, 'f2' => FileATraiter::FusionAControler->value]);

            // À trier : 1re séance de l'annonce (gardée dans l'élément) et nom de la commune donné par la source.
            DB::update(<<<'SQL'
                update elements_a_traiter e set
                    echeance = case when left(e.donnees->>'debut', 10)::date >= :j then left(e.donnees->>'debut', 10)::date end,
                    ville_pilote = exists (
                        select 1 from villes v where v.est_pilote
                          and (lower(e.donnees->>'ville') = lower(v.nom) or lower(e.donnees->>'ville') like lower(v.nom) || ' %'))
                where e.statut = :s and e.file = :f and e.donnees->>'debut' is not null
                SQL, ['j' => $aujourdhui, 's' => $enAttente, 'f' => FileATraiter::ATrier->value]);

            // Lieux : commune du lieu, prochaine représentation programmée dans ce lieu.
            DB::update(<<<SQL
                update elements_a_traiter e set
                    echeance = (select min({$prochainJour('r')}) from representations r
                                where r.lieu_id = e.cible_id and r.statut = :p and coalesce(r.date_fin, r.date_locale) >= :j),
                    ville_pilote = coalesce((select v.est_pilote from lieux l join villes v on v.id = l.ville_id where l.id = e.cible_id), false)
                where e.statut = :s and e.file = :f
                SQL, ['j' => $aujourdhui, 'p' => $programmee, 's' => $enAttente, 'f' => FileATraiter::LieuAVerifier->value]);

            // Spectacles à contrôler : prochaine représentation du spectacle, et s'il passe par une ville pilote.
            DB::update(<<<SQL
                update elements_a_traiter e set
                    echeance = (select min({$prochainJour('r')}) from representations r
                                where r.spectacle_id = e.cible_id and r.statut = :p and coalesce(r.date_fin, r.date_locale) >= :j),
                    ville_pilote = exists (select 1 from representations r join villes v on v.id = r.ville_id
                                where r.spectacle_id = e.cible_id and v.est_pilote and r.statut = :p2 and coalesce(r.date_fin, r.date_locale) >= :j2)
                where e.statut = :s and e.file = :f
                SQL, ['j' => $aujourdhui, 'j2' => $aujourdhui, 'p' => $programmee, 'p2' => $programmee, 's' => $enAttente, 'f' => FileATraiter::SpectacleAControler->value]);

            return DB::update(<<<'SQL'
                update elements_a_traiter set urgence = case
                    when file = :classer then case when (donnees->>'nb_annonces')::int >= :n then 1 else 0 end
                    when echeance = :j and ville_pilote then 3
                    when echeance = :j2 or (ville_pilote and echeance <= :proche) then 2
                    when ville_pilote or echeance <= :proche2 then 1
                    else 0 end
                where statut = :s
                SQL, [
                'classer' => FileATraiter::AClasser->value, 'n' => self::ANNONCES_A_CLASSER_URGENTES,
                'j' => $aujourdhui, 'j2' => $aujourdhui,
                'proche' => $proche, 'proche2' => $proche,
                's' => $enAttente,
            ]);
        });
    }
}
