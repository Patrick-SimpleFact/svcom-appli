<?php

namespace App\Mesure;

use App\Http\Controllers\Api\AppareilController;
use App\Models\ClicSortant;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Événements d'usage envoyés par lots par l'app (API §11, F1.9, F2.11, F3.7, F6.5).
 * Rien de personnel : l'appareil est gardé par son empreinte, la position n'est jamais enregistrée
 * (elle sert seulement à trouver la commune, pour les statistiques par ville).
 * Table partitionnée par mois : la partition du mois est créée à la première écriture ; supprimée après 13 mois.
 */
class Evenements
{
    public const MAX_PAR_LOT = 200;

    public const MOIS_CONSERVATION = 13;

    /** Un événement plus ancien (téléphone resté hors ligne) ou daté dans le futur est ignoré. */
    public const JOURS_RETARD_MAX = 7;

    public const TAILLE_DONNEES_MAX = 2000;

    /** Jamais de position dans les données (F2.11). */
    private const CLES_INTERDITES = ['lat', 'lon', 'lng', 'latitude', 'longitude', 'position', 'email', 'telephone'];

    /** @var array<string, true> partitions déjà vérifiées pendant cette requête */
    private array $partitions = [];

    /**
     * @param  list<array{type: string, horodatage: string, ville_id?: int|null, donnees?: array|null}>  $evenements
     * @return array{recus: int, ignores: int}
     */
    public function enregistrer(string $appareil, array $evenements, ?Point $position): array
    {
        $empreinte = ClicSortant::empreinte($appareil);
        $villePosition = $position ? AppareilController::communeProche($position) : null;
        $lignes = [];

        foreach ($evenements as $e) {
            $quand = CarbonImmutable::parse($e['horodatage'])->utc();

            if ($quand->lessThan(now()->subDays(self::JOURS_RETARD_MAX)) || $quand->greaterThan(now()->addHour())) {
                continue;
            }

            $donnees = array_diff_key($e['donnees'] ?? [], array_flip(self::CLES_INTERDITES));
            $json = $donnees === [] ? null : json_encode($donnees, JSON_UNESCAPED_UNICODE);

            $lignes[] = [
                'horodatage' => $quand->toIso8601String(),
                'appareil_hash' => $empreinte,
                'type' => $e['type'],
                'ville_id' => $e['ville_id'] ?? $villePosition,
                'donnees' => $json !== null && strlen($json) <= self::TAILLE_DONNEES_MAX ? $json : null,
            ];
        }

        foreach (collect($lignes)->pluck('horodatage')->map(fn ($h) => CarbonImmutable::parse($h)->startOfMonth())->unique() as $mois) {
            $this->assurerPartition($mois);
        }

        if ($lignes !== []) {
            DB::table('evenements_app')->insert($lignes);
        }

        return ['recus' => count($lignes), 'ignores' => count($evenements) - count($lignes)];
    }

    /** Crée la partition d'un mois (UTC) si elle n'existe pas encore. */
    public function assurerPartition(CarbonImmutable $mois): void
    {
        $debut = $mois->utc()->startOfMonth();
        $nom = 'evenements_app_'.$debut->format('Y_m');

        if (isset($this->partitions[$nom])) {
            return;
        }

        DB::statement(sprintf(
            "create table if not exists %s partition of evenements_app for values from ('%s') to ('%s')",
            $nom, $debut->toDateTimeString().'+00', $debut->addMonth()->toDateTimeString().'+00',
        ));
        $this->partitions[$nom] = true;
    }

    /** Supprime les mois de plus de 13 mois (F7.15) ; leurs chiffres utiles sont déjà dans stats_quotidiennes. */
    public function purger(): array
    {
        $limite = now()->utc()->startOfMonth()->subMonths(self::MOIS_CONSERVATION)->format('Y_m');

        return collect(DB::select("select inhrelid::regclass::text as nom from pg_inherits where inhparent = 'evenements_app'::regclass"))
            ->pluck('nom')
            ->filter(fn (string $nom) => preg_match('/^evenements_app_(\d{4}_\d{2})$/', $nom, $m) && $m[1] < $limite)
            ->each(fn (string $nom) => DB::statement("drop table {$nom}"))
            ->values()->all();
    }
}
