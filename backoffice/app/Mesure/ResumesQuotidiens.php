<?php

namespace App\Mesure;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Résumés quotidiens (SCHEMA §9 stats_quotidiennes) : les chiffres utiles d'une journée (heure de Paris),
 * par ville et au total (ville vide), et par source pour les clics. Ils survivent à la purge des détails (13 mois).
 * Recalculer une journée remplace ses chiffres (commande relançable).
 */
class ResumesQuotidiens
{
    /** Le KPI de l'accueil : un spectacle choisi en moins de 30 secondes (F2.11). */
    public const SECONDES_PREMIER_CLIC = 30;

    /** @return int nombre de lignes écrites */
    public function calculer(CarbonImmutable $jour): int
    {
        $jour = $jour->setTimezone('Europe/Paris')->startOfDay();
        $p = ['jour' => $jour->toDateString(), 'debut' => $jour->utc()->toIso8601String(), 'fin' => $jour->addDay()->utc()->toIso8601String()];

        return DB::transaction(function () use ($p) {
            DB::table('stats_quotidiennes')->where('jour', $p['jour'])->delete();

            $parVille = fn (string $indicateur, string $valeur, string $depuis) => DB::affectingStatement(<<<SQL
                insert into stats_quotidiennes (jour, indicateur, ville_id, valeur)
                select :jour, {$indicateur}, ville_id, valeur from (
                    select ville_id, grouping(ville_id) as total, {$valeur} as valeur {$depuis} group by grouping sets ((ville_id), ())
                ) r where r.total = 1 or r.ville_id is not null
                SQL, $p);

            return array_sum([
                // Événements de l'app : un indicateur par type (ouverture, fiche_vue, vue_carte…).
                DB::affectingStatement(<<<'SQL'
                    insert into stats_quotidiennes (jour, indicateur, ville_id, valeur)
                    select :jour, 'evenement:' || type, ville_id, n from (
                        select type, ville_id, grouping(ville_id) as total, count(*) as n
                        from evenements_app where horodatage >= :debut and horodatage < :fin
                        group by grouping sets ((type, ville_id), (type))
                    ) r where r.total = 1 or r.ville_id is not null
                    SQL, $p),
                $parVille("'appareils_actifs'", 'count(distinct appareil_hash)', 'from (select ville_id, appareil_hash from evenements_app where horodatage >= :debut and horodatage < :fin) e'),
                $parVille("'premier_clic_moins_30s'", 'count(*)', "from evenements_app where horodatage >= :debut and horodatage < :fin and type = 'premier_clic_carte'
                    and (donnees->>'secondes') ~ '^[0-9.]+$' and (donnees->>'secondes')::numeric < ".self::SECONDES_PREMIER_CLIC),
                $parVille("'recherches'", 'count(*)', 'from recherches where cree_le >= :debut and cree_le < :fin'),
                $parVille("'recherches_sans_resultat'", 'count(*)', 'from recherches where cree_le >= :debut and cree_le < :fin and nb_resultats = 0'),
                $parVille("'pistes_recues'", 'count(*)', 'from pistes where created_at >= :debut and created_at < :fin'),
                $parVille("'signalements_recus'", 'count(*)', 'from (select r.ville_id from signalements s join representations r on r.id = s.representation_id where s.created_at >= :debut and s.created_at < :fin) s'),
                // Clics comptés vers les billetteries (F7.13 bis) : par ville, par source, et au total.
                DB::affectingStatement(<<<'SQL'
                    insert into stats_quotidiennes (jour, indicateur, ville_id, source_id, valeur)
                    select :jour, 'clics_billetterie', ville_id, source_id, n from (
                        select ville_id, source_id, grouping(ville_id) as tv, grouping(source_id) as ts, count(*) as n
                        from clics_sortants where compte and horodatage >= :debut and horodatage < :fin
                        group by grouping sets ((ville_id), (source_id), ())
                    ) r where (r.tv = 1 or r.ville_id is not null) and (r.ts = 1 or r.source_id is not null)
                    SQL, $p),
                // Suggestions à l'ouverture (F6.5), au total.
                DB::affectingStatement(<<<'SQL'
                    insert into stats_quotidiennes (jour, indicateur, valeur)
                    select :jour, i, n from (
                        select 'suggestions_auto' as i, count(*) filter (where campagne_id is null) as n from affichages_suggestion where affiche_le >= :debut and affiche_le < :fin
                        union all select 'suggestions_sponsorisees', count(*) filter (where campagne_id is not null) from affichages_suggestion where affiche_le >= :debut and affiche_le < :fin
                    ) s where n > 0
                    SQL, $p),
            ]);
        });
    }
}
