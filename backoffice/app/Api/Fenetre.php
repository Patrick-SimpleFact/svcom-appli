<?php

namespace App\Api;

use App\Exceptions\ErreurApi;
use App\Models\Representation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fenêtre de temps d'une recherche (F2.2), à l'heure locale du point de recherche :
 * - ce soir : le jour en cours, séances pas commencées depuis plus de 15 min (une séance à 0 h 30 compte pour la veille) ;
 * - demain ;
 * - week-end : du vendredi 18 h au dimanche (le week-end en cours s'il a commencé) ;
 * - une date précise, jusqu'à 3 mois.
 * Les représentations sans horaire (journée, période, continu) comptent si elles couvrent un jour de la fenêtre.
 */
final readonly class Fenetre
{
    public const QUANDS = ['ce_soir', 'demain', 'week_end'];

    public const MINUTES_APRES_DEBUT = 15;

    public const MOIS_MAX = 3;

    private function __construct(
        public string $quand,
        public CarbonImmutable $premierJour,
        public CarbonImmutable $dernierJour,
        /** Séances qui commencent avant cet instant : exclues (déjà commencées, ou vendredi avant 18 h). */
        public ?CarbonImmutable $pasAvant,
    ) {}

    public static function pour(string $quand, string $fuseau): self
    {
        $maintenant = CarbonImmutable::now($fuseau);
        $aujourdhui = $maintenant->subHours(Representation::HEURE_FIN_DE_SOIREE)->startOfDay();
        $dejaCommencees = $maintenant->subMinutes(self::MINUTES_APRES_DEBUT);

        return match (true) {
            $quand === 'ce_soir' => new self($quand, $aujourdhui, $aujourdhui, $dejaCommencees),
            $quand === 'demain' => new self($quand, $aujourdhui->addDay(), $aujourdhui->addDay(), null),
            $quand === 'week_end' => self::weekEnd($aujourdhui, $dejaCommencees),
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $quand) => self::date($quand, $fuseau, $aujourdhui, $dejaCommencees),
            default => throw new ErreurApi('quand_invalide', 'quand : ce_soir, demain, week_end ou une date AAAA-MM-JJ.', 422),
        };
    }

    private static function weekEnd(CarbonImmutable $aujourdhui, CarbonImmutable $dejaCommencees): self
    {
        // Vendredi, samedi ou dimanche : le week-end en cours ; sinon le prochain.
        $vendredi = $aujourdhui->dayOfWeekIso >= 5 ? $aujourdhui->subDays($aujourdhui->dayOfWeekIso - 5) : $aujourdhui->next(CarbonImmutable::FRIDAY);

        return new self('week_end', $aujourdhui->max($vendredi), $vendredi->addDays(2), $dejaCommencees->max($vendredi->setTime(18, 0)));
    }

    private static function date(string $date, string $fuseau, CarbonImmutable $aujourdhui, CarbonImmutable $dejaCommencees): self
    {
        $jour = CarbonImmutable::createFromFormat('!Y-m-d', $date, $fuseau);

        if ($jour === false || $jour->lessThan($aujourdhui) || $jour->greaterThan($aujourdhui->addMonths(self::MOIS_MAX))) {
            throw new ErreurApi('date_invalide', 'La date doit être comprise entre aujourd’hui et dans '.self::MOIS_MAX.' mois.', 422);
        }

        return new self($date, $jour, $jour, $jour->isSameDay($aujourdhui) ? $dejaCommencees : null);
    }

    /** Restreint une requête de représentations à la fenêtre. */
    public function appliquer(Builder $requete): Builder
    {
        [$premier, $dernier] = [$this->premierJour->toDateString(), $this->dernierJour->toDateString()];

        return $requete
            ->where('representations.date_locale', '<=', $dernier)
            ->whereRaw('coalesce(representations.date_fin, representations.date_locale) >= ?', [$premier])
            ->where(fn (Builder $q) => $q
                ->whereNull('representations.debut')
                ->orWhere(fn (Builder $s) => $s->where('representations.type', 'seance')->when($this->pasAvant, fn ($w) => $w->where('representations.debut', '>=', $this->pasAvant->utc())))
                ->orWhere(fn (Builder $c) => $c->where('representations.type', 'continu')->where(fn ($f) => $f->whereNull('representations.fin')->orWhere('representations.fin', '>', now()))));
    }
}
