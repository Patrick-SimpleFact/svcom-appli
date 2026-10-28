<?php

namespace App\Statistiques;

use App\Models\ActionCommunication;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Audience de l'app (Statistiques › Audience) : installations, ouvertures, fidélité et parcours, jour par jour à l'heure de Paris,
 * jusqu'à un jour de référence (aujourd'hui par défaut). Un « appareil » est une installation (une réinstallation compte comme
 * un nouvel appareil). Les appareils de test (case « Appareil de test ») et ceux du compte de démonstration des boutiques
 * sont écartés de tous les chiffres. Les ouvertures viennent des événements envoyés par l'app : elles restent justes pour un jour passé.
 */
class Audience
{
    public const FUSEAU = 'Europe/Paris';

    /** Appareils comptés : ni de test, ni du compte d'examen des boutiques. */
    public function appareils(): Builder
    {
        $examen = (string) config('app.compte_examen.email');

        return DB::table('appareils')
            ->where('est_test', false)
            ->when($examen !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNull('utilisateur_id')
                ->orWhereNotIn('utilisateur_id', fn (Builder $s) => $s->select('id')->from('utilisateurs')->whereRaw('lower(email) = ?', [$examen]))));
    }

    /**
     * Empreintes comptées dans les événements et les clics : celles des appareils comptés. Les autres sont écartées :
     * appareils de test et du compte des boutiques, anciennes installations disparues, clics venus du site web
     * (leur empreinte est celle de l'adresse IP, pas d'un téléphone).
     */
    public function empreintesComptees(): Builder
    {
        return $this->appareils()->select('appareil_hash')->whereNotNull('appareil_hash');
    }

    /**
     * Les chiffres au jour de référence : appareils installés jusqu'à ce jour, nouveaux et ouverts ce jour-là,
     * actifs sur les 7 et 30 jours qui finissent ce jour-là, revenus (rouverts un autre jour que celui de l'installation).
     *
     * @return array{total: int, ios: int, android: int, nouveaux_jour: int, ouverts_jour: int, actifs_7j: int, actifs_30j: int, revenus: int, comptes: int, comptes_jour: int}
     */
    public function compteurs(?CarbonImmutable $reference = null): array
    {
        $jour = $this->reference($reference);
        $fin = $jour->addDay()->utc();

        $c = $this->appareils()->from('appareils as a')->where('a.premiere_ouverture', '<', $fin)->selectRaw(
            "count(*) as total,
            count(*) filter (where a.plateforme = 'ios') as ios,
            count(*) filter (where a.plateforme = 'android') as android,
            count(*) filter (where a.premiere_ouverture >= ?) as nouveaux_jour,
            count(*) filter (where exists (select 1 from evenements_app e where e.appareil_hash = a.appareil_hash and e.type = 'ouverture'
                and e.horodatage < ? and {$this->dateSql('e.horodatage')} > {$this->dateSql('a.premiere_ouverture')})) as revenus",
            [$jour->utc(), $fin])->first();

        $ouverts = fn (int $nbJours) => (int) DB::table('evenements_app')->where('type', 'ouverture')
            ->where('horodatage', '>=', $jour->subDays($nbJours - 1)->utc())->where('horodatage', '<', $fin)
            ->whereIn('appareil_hash', $this->empreintesComptees())->distinct()->count('appareil_hash');

        $comptes = $this->utilisateurs()->where('created_at', '<', $fin)
            ->selectRaw('count(*) as total, count(*) filter (where created_at >= ?) as jour', [$jour->utc()])->first();

        return [
            'total' => (int) $c->total, 'ios' => (int) $c->ios, 'android' => (int) $c->android,
            'nouveaux_jour' => (int) $c->nouveaux_jour,
            'ouverts_jour' => $ouverts(1), 'actifs_7j' => $ouverts(7), 'actifs_30j' => $ouverts(30),
            'revenus' => (int) $c->revenus,
            'comptes' => (int) $comptes->total, 'comptes_jour' => (int) $comptes->jour,
        ];
    }

    /**
     * Nouveaux appareils par jour, iOS et Android, sur les jours qui finissent au jour de référence.
     *
     * @return array{jours: list<string>, ios: list<int>, android: list<int>}
     */
    public function nouveauxParJour(int $nbJours = 30, ?CarbonImmutable $reference = null): array
    {
        [$jours, $debut, $fin] = $this->periode($nbJours, $reference);
        $lignes = $this->appareils()
            ->selectRaw($this->jourSql('premiere_ouverture').' as jour')
            ->selectRaw("count(*) filter (where plateforme = 'ios') as ios, count(*) filter (where plateforme = 'android') as android")
            ->where('premiere_ouverture', '>=', $debut)->where('premiere_ouverture', '<', $fin)
            ->groupBy('jour')->get()->keyBy('jour');

        return [
            'jours' => $jours,
            'ios' => array_map(fn (string $j) => (int) ($lignes[$j]->ios ?? 0), $jours),
            'android' => array_map(fn (string $j) => (int) ($lignes[$j]->android ?? 0), $jours),
        ];
    }

    /**
     * Nouveaux appareils heure par heure, pour un jour et un jour de comparaison (par défaut aujourd'hui et la veille).
     * Les heures pas encore passées d'aujourd'hui sont vides (null), pas à zéro.
     *
     * @return array{jour: list<int|null>, comparaison: list<int|null>}
     */
    public function nouveauxParHeure(?CarbonImmutable $jour = null, ?CarbonImmutable $comparaison = null): array
    {
        $jour = $this->reference($jour);
        $comparaison = $this->reference($comparaison ?? $jour->subDay());

        return ['jour' => $this->parHeure($jour), 'comparaison' => $this->parHeure($comparaison)];
    }

    /** @return list<int|null> les 24 heures d'un jour (heure de Paris) */
    private function parHeure(CarbonImmutable $jour): array
    {
        $lignes = $this->appareils()
            ->selectRaw("extract(hour from premiere_ouverture at time zone '".self::FUSEAU."')::int as heure, count(*) as n")
            ->where('premiere_ouverture', '>=', $jour->utc())
            ->where('premiere_ouverture', '<', $jour->addDay()->utc())
            ->groupBy('heure')->pluck('n', 'heure');
        $maintenant = CarbonImmutable::now(self::FUSEAU);

        return array_map(fn (int $h) => $jour->setTime($h, 0)->greaterThan($maintenant) ? null : (int) ($lignes[$h] ?? 0), range(0, 23));
    }

    /**
     * Appareils actifs par jour : ceux qui ont ouvert l'app ce jour-là (événements « ouverture »).
     *
     * @return array{jours: list<string>, actifs: list<int>}
     */
    public function actifsParJour(int $nbJours = 30, ?CarbonImmutable $reference = null): array
    {
        [$jours, $debut, $fin] = $this->periode($nbJours, $reference);
        $lignes = DB::table('evenements_app')
            ->selectRaw($this->jourSql('horodatage').' as jour, count(distinct appareil_hash) as n')
            ->where('type', 'ouverture')
            ->where('horodatage', '>=', $debut)->where('horodatage', '<', $fin)
            ->whereIn('appareil_hash', $this->empreintesComptees())
            ->groupBy('jour')->pluck('n', 'jour');

        return ['jours' => $jours, 'actifs' => array_map(fn (string $j) => (int) ($lignes[$j] ?? 0), $jours)];
    }

    /**
     * Comptes créés par jour (hors compte de démonstration des boutiques).
     *
     * @return array{jours: list<string>, comptes: list<int>}
     */
    public function comptesParJour(int $nbJours = 30, ?CarbonImmutable $reference = null): array
    {
        [$jours, $debut, $fin] = $this->periode($nbJours, $reference);
        $lignes = $this->utilisateurs()
            ->selectRaw($this->jourSql('created_at').' as jour, count(*) as n')
            ->where('created_at', '>=', $debut)->where('created_at', '<', $fin)
            ->groupBy('jour')->pluck('n', 'jour');

        return ['jours' => $jours, 'comptes' => array_map(fn (string $j) => (int) ($lignes[$j] ?? 0), $jours)];
    }

    /**
     * Fidélité des nouveaux appareils, jour d'installation par jour d'installation (jusqu'au jour de référence) : combien ont
     * rouvert l'app le lendemain, et dans les 7 jours suivants. null tant que la période n'est pas finie (lendemain pas
     * terminé, 7 jours pas écoulés), à la date d'aujourd'hui.
     *
     * @return list<array{jour: string, nouveaux: int, lendemain: int|null, sept_jours: int|null}>
     */
    public function fidelite(int $nbJours = 30, ?CarbonImmutable $reference = null): array
    {
        [, $debut, $fin] = $this->periode($nbJours, $reference);
        $aujourdhui = $this->debutDuJour();
        $jourInstallation = $this->dateSql('a.premiere_ouverture');
        $jourEvenement = $this->dateSql('e.horodatage');
        $revenu = fn (int $de, int $a) => "count(*) filter (where exists (select 1 from evenements_app e where e.appareil_hash = a.appareil_hash
            and e.type = 'ouverture' and e.horodatage >= a.premiere_ouverture and e.horodatage < a.premiere_ouverture + interval '9 days'
            and {$jourEvenement} between {$jourInstallation} + {$de} and {$jourInstallation} + {$a}))";

        $lignes = $this->appareils()->from('appareils as a')
            ->selectRaw($this->jourSql('a.premiere_ouverture')." as jour, count(*) as nouveaux, {$revenu(1, 1)} as lendemain, {$revenu(1, 7)} as sept_jours")
            ->whereNotNull('a.appareil_hash')
            ->where('a.premiere_ouverture', '>=', $debut)->where('a.premiere_ouverture', '<', $fin)
            ->groupBy('jour')->orderBy('jour')->get();

        return $lignes->map(fn ($l) => [
            'jour' => $l->jour,
            'nouveaux' => (int) $l->nouveaux,
            // Le lendemain n'est complet qu'une fois passé ; les 7 jours, une fois écoulés.
            'lendemain' => CarbonImmutable::parse($l->jour, self::FUSEAU)->addDays(2)->lessThanOrEqualTo($aujourdhui) ? (int) $l->lendemain : null,
            'sept_jours' => CarbonImmutable::parse($l->jour, self::FUSEAU)->addDays(8)->lessThanOrEqualTo($aujourdhui) ? (int) $l->sept_jours : null,
        ])->values()->all();
    }

    /**
     * Le parcours, jour par jour (du jour de référence vers le passé) : appareils qui ont ouvert l'app, vu une fiche,
     * puis cliqué vers une billetterie.
     *
     * @return list<array{jour: string, actifs: int, fiches: int, clics: int}>
     */
    public function parcours(int $nbJours = 14, ?CarbonImmutable $reference = null): array
    {
        [$jours, $debut, $fin] = $this->periode($nbJours, $reference);
        $parType = DB::table('evenements_app')
            ->selectRaw($this->jourSql('horodatage').' as jour')
            ->selectRaw("count(distinct appareil_hash) filter (where type = 'ouverture') as actifs, count(distinct appareil_hash) filter (where type = 'fiche_vue') as fiches")
            ->where('horodatage', '>=', $debut)->where('horodatage', '<', $fin)->whereIn('type', ['ouverture', 'fiche_vue'])
            ->whereIn('appareil_hash', $this->empreintesComptees())
            ->groupBy('jour')->get()->keyBy('jour');
        $clics = DB::table('clics_sortants')
            ->selectRaw($this->jourSql('horodatage').' as jour, count(distinct appareil_hash) as n')
            ->where('horodatage', '>=', $debut)->where('horodatage', '<', $fin)
            ->whereIn('appareil_hash', $this->empreintesComptees())
            ->groupBy('jour')->pluck('n', 'jour');

        return array_reverse(array_map(fn (string $j) => [
            'jour' => $j,
            'actifs' => (int) ($parType[$j]->actifs ?? 0),
            'fiches' => (int) ($parType[$j]->fiches ?? 0),
            'clics' => (int) ($clics[$j] ?? 0),
        ], $jours));
    }

    /**
     * Actions de communication de la période, regroupées par jour (« AAAA-MM-JJ ») ou par heure (« AAAA-MM-JJ HH »).
     *
     * @return array<string, list<string>> clé → « 14h30 · Instagram · Post de lancement »
     */
    public function actions(DateTimeInterface $debut, DateTimeInterface $fin, bool $parHeure = false): array
    {
        $actions = [];

        foreach (ActionCommunication::whereBetween('date_heure', [CarbonImmutable::instance($debut)->utc(), CarbonImmutable::instance($fin)->utc()])->orderBy('date_heure')->get() as $action) {
            $quand = CarbonImmutable::parse($action->date_heure)->setTimezone(self::FUSEAU);
            $actions[$quand->format($parHeure ? 'Y-m-d H' : 'Y-m-d')][] = $quand->format('H\hi').' · '.$action->canal.' · '.$action->libelle;
        }

        return $actions;
    }

    /** Minuit à Paris. À passer en UTC (->utc()) dans les requêtes : les dates y sont écrites sans leur fuseau. */
    public function debutDuJour(): CarbonImmutable
    {
        return CarbonImmutable::now(self::FUSEAU)->startOfDay();
    }

    /** Le jour de référence (minuit à Paris), jamais après aujourd'hui ; aujourd'hui par défaut. */
    public function reference(?DateTimeInterface $jour = null): CarbonImmutable
    {
        $aujourdhui = $this->debutDuJour();

        return $jour === null ? $aujourdhui : min(CarbonImmutable::instance($jour)->setTimezone(self::FUSEAU)->startOfDay(), $aujourdhui);
    }

    /** Le jour de référence à partir du filtre de la page (« AAAA-MM-JJ », vide ou illisible : aujourd'hui). */
    public function referenceFiltre(mixed $jour): CarbonImmutable
    {
        return rescue(fn () => $this->reference(filled($jour) ? CarbonImmutable::parse((string) $jour, self::FUSEAU) : null), fn () => $this->reference(), false);
    }

    /** @return list<string> les jours qui finissent au jour de référence, du plus ancien au plus récent (« AAAA-MM-JJ », heure de Paris) */
    public function jours(int $nbJours, ?CarbonImmutable $reference = null): array
    {
        $fin = $this->reference($reference);

        return array_map(fn (int $i) => $fin->subDays($i)->toDateString(), range($nbJours - 1, 0));
    }

    /** @return array{list<string>, CarbonImmutable, CarbonImmutable} les jours, puis le début et la fin (exclue) de la période, en UTC */
    private function periode(int $nbJours, ?CarbonImmutable $reference): array
    {
        $fin = $this->reference($reference);

        return [$this->jours($nbJours, $fin), $fin->subDays($nbJours - 1)->utc(), $fin->addDay()->utc()];
    }

    private function utilisateurs(): Builder
    {
        $examen = (string) config('app.compte_examen.email');

        return DB::table('utilisateurs')->when($examen !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNull('email')->orWhereRaw('lower(email) <> ?', [$examen])));
    }

    /** Le jour (heure de Paris) d'une colonne horodatée, en date SQL. */
    private function dateSql(string $colonne): string
    {
        return "({$colonne} at time zone '".self::FUSEAU."')::date";
    }

    /** Le jour (heure de Paris) d'une colonne horodatée, en texte « AAAA-MM-JJ ». */
    private function jourSql(string $colonne): string
    {
        return "to_char({$colonne} at time zone '".self::FUSEAU."', 'YYYY-MM-DD')";
    }
}
