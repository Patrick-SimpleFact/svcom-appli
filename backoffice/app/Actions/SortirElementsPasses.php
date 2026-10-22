<?php

namespace App\Actions;

use App\Enums\FileATraiter;
use App\Enums\StatutElement;
use App\Models\Source;
use Illuminate\Support\Facades\DB;

/**
 * Sort de la boîte de travail les éléments dont toutes les séances sont passées (« sans objet ») :
 * - À trier : la 1re séance de l'annonce est passée ;
 * - À classer : la source n'a plus aucune séance à venir dans cette catégorie ;
 * - Doublons probables, Fusions à contrôler : les deux séances sont passées.
 * Rien d'autre n'est touché. Une annonce encore au programme revient d'elle-même à la collecte suivante de sa source
 * (« sans objet » n'est pas une décision : ni garder, ni exclure, ni genre, ni fusion).
 * Lancé à la main sur le stock (`boite:sortir-passes`), pas à chaque entretien : pour une annonce sur une période,
 * la 1re séance gardée dans l'élément peut être passée alors que le spectacle se joue encore.
 */
class SortirElementsPasses
{
    public const DECISION = '{"sans_objet":"séances passées"}';

    /** @return array<string, int> nombre d'éléments par file (valeur de FileATraiter) */
    public function handle(bool $simulation = false): array
    {
        $jour = PrioriserBoiteDeTravail::aujourdhui()->toDateString();
        $resultat = [];

        foreach ($this->conditions() as $file => [$condition, $parametres]) {
            $liaisons = ['file' => $file, 'attente' => StatutElement::EnAttente->value, 'j' => $jour, ...$parametres];
            $where = "e.statut = :attente and e.file = :file and {$condition}";

            $resultat[$file] = $simulation
                ? (int) DB::selectOne("select count(*) as n from elements_a_traiter e where {$where}", $liaisons)->n
                : DB::update(
                    "update elements_a_traiter e set statut = :traite, decision = :decision, traite_le = now(), updated_at = now() where {$where}",
                    [...$liaisons, 'traite' => StatutElement::Traite->value, 'decision' => self::DECISION],
                );
        }

        return $resultat;
    }

    /** @return array<string, array{string, array<string, string>}> condition SQL « passé » par file */
    private function conditions(): array
    {
        // Une séance est à venir si son dernier jour (date_fin, sinon date_locale) n'est pas passé ; sans date, on garde.
        $aVenir = 'coalesce(o.date_fin, o.date_locale) is null or coalesce(o.date_fin, o.date_locale) >= :j';

        $paire = <<<SQL
            not exists (select 1 from offres o
                        where o.id in ((e.donnees->>'offre_a_id')::bigint, (e.donnees->>'offre_b_id')::bigint) and ({$aVenir}))
            SQL;

        return [
            FileATraiter::ATrier->value => [
                "e.donnees->>'debut' is not null and left(e.donnees->>'debut', 10)::date < :j",
                [],
            ],
            FileATraiter::AClasser->value => [
                <<<SQL
                    e.cible_type = :source and not exists (
                        select 1 from offres o
                        cross join lateral jsonb_array_elements_text(coalesce(o.donnees_normalisees->'categories_source', '[]'::jsonb)) as c(categorie)
                        where o.source_id = e.cible_id and o.disparue_le is null and ({$aVenir})
                          and trim(c.categorie) = e.donnees->>'categorie')
                    SQL,
                ['source' => (new Source)->getMorphClass()],
            ],
            FileATraiter::DoublonProbable->value => [$paire, []],
            FileATraiter::FusionAControler->value => [$paire, []],
        ];
    }
}
