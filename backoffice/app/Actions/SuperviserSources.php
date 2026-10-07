<?php

namespace App\Actions;

use App\Enums\StatutCollecte;
use App\Enums\TypeAlerte;
use App\Mail\AlertesSupervision;
use App\Models\Admin;
use App\Models\Alerte;
use App\Models\Collecte;
use App\Models\Parametre;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Supervision des sources (F7.9, COLLECTE §9) : ouvre ou résout les alertes de chaque source active,
 * puis envoie un seul e-mail pour les alertes nouvelles et résolues.
 *
 * - Collecte en échec : la dernière collecte terminée a été abandonnée après ses 4 essais.
 * - Publication manquante : à partir de 7 h, aucune collecte réussie depuis la veille 7 h.
 * - Chute du volume : la dernière collecte réussie reçoit 30 % de moins que la moyenne des 7 jours précédents.
 * - Données périmées : dernière collecte réussie plus ancienne que le réglage « donnees_perimees_h » (36 h).
 *
 * Une source désactivée n'est pas surveillée (ses alertes sont résolues) ; une source masquée l'est toujours.
 */
class SuperviserSources
{
    /** Collectes réussies nécessaires dans les 7 jours pour juger d'une chute de volume. */
    public const COLLECTES_MIN_POUR_MOYENNE = 3;

    /** @return array{ouvertes: Collection<int, Alerte>, resolues: Collection<int, Alerte>} */
    public function handle(?Source $seule = null): array
    {
        $ouvertes = collect();
        $resolues = collect();

        $sources = $seule ? collect([$seule->fresh()]) : Source::all();

        foreach ($sources as $source) {
            $constats = $source->actif ? $this->constater($source) : [];

            foreach (TypeAlerte::cases() as $type) {
                $alerte = $source->alertes()->ouvertes()->where('type', $type)->first();

                if (array_key_exists($type->value, $constats)) {
                    if ($alerte === null) {
                        $ouvertes->push($source->alertes()->create(['type' => $type, 'message' => $constats[$type->value], 'ouverte_le' => now()]));
                    } else {
                        $alerte->update(['message' => $constats[$type->value]]);
                    }
                } elseif ($alerte !== null) {
                    $alerte->update(['resolue_le' => now()]);
                    $resolues->push($alerte);
                }
            }
        }

        $this->notifier($ouvertes, $resolues);

        return ['ouvertes' => $ouvertes, 'resolues' => $resolues];
    }

    /** @return array<string, string> type d'alerte → message */
    private function constater(Source $source): array
    {
        $constats = [];
        $reussies = Collecte::where('source_id', $source->id)->where('statut', StatutCollecte::Reussie);
        $derniereReussie = (clone $reussies)->latest('fin')->first();
        $derniereTerminee = Collecte::where('source_id', $source->id)->whereNot('statut', StatutCollecte::EnCours)->latest('id')->first();

        if ($derniereTerminee?->statut === StatutCollecte::Abandonnee) {
            $constats[TypeAlerte::Echec->value] = 'Collecte du '.self::date($derniereTerminee->debut).' abandonnée après 4 essais : '
                .mb_strimwidth((string) $derniereTerminee->erreur, 0, 300, '…');
        }

        $heureControle = CarbonImmutable::createFromFormat('H:i', (string) Parametre::valeur('alerte_heure_publication'), 'Europe/Paris');
        $controle = CarbonImmutable::now('Europe/Paris')->setTime($heureControle->hour, $heureControle->minute);

        if (now()->greaterThanOrEqualTo($controle) && ($derniereReussie === null || $derniereReussie->fin->lessThan($controle->subDay()))) {
            $constats[TypeAlerte::PublicationManquante->value] = 'Aucune publication réussie depuis hier '.$controle->format('H:i')
                .($derniereReussie ? ' (dernière : '.self::date($derniereReussie->fin).').' : ' (jamais publiée).');
        }

        $perimeesH = (int) Parametre::valeur('donnees_perimees_h');

        if ($derniereReussie !== null && $derniereReussie->fin->lessThan(now()->subHours($perimeesH))) {
            $constats[TypeAlerte::DonneesPerimees->value] = "Données de plus de {$perimeesH} h servies à l’app (dernière publication : ".self::date($derniereReussie->fin).').';
        }

        if ($derniereReussie !== null) {
            $precedentes = (clone $reussies)->whereKeyNot($derniereReussie->id)
                ->where('fin', '>=', $derniereReussie->fin->subDays(7))->where('fin', '<', $derniereReussie->fin)
                ->pluck('nb_recus');
            $seuil = (int) Parametre::valeur('alerte_chute_volume_pct');

            if ($precedentes->count() >= self::COLLECTES_MIN_POUR_MOYENNE) {
                $moyenne = $precedentes->avg();

                if ($moyenne > 0 && $derniereReussie->nb_recus < $moyenne * (1 - $seuil / 100)) {
                    $constats[TypeAlerte::ChuteVolume->value] = sprintf(
                        '%s annonces reçues le %s, contre %s en moyenne sur 7 jours (−%d %%) : flux probablement incomplet.',
                        number_format($derniereReussie->nb_recus, 0, ',', ' '), self::date($derniereReussie->fin),
                        number_format($moyenne, 0, ',', ' '), round(100 * (1 - $derniereReussie->nb_recus / $moyenne)),
                    );
                }
            }
        }

        return $constats;
    }

    /** Un seul e-mail pour toutes les alertes nouvelles et résolues de ce passage. */
    private function notifier(Collection $ouvertes, Collection $resolues): void
    {
        if ($ouvertes->isEmpty() && $resolues->isEmpty()) {
            return;
        }

        $destinataires = self::destinataires();

        if ($destinataires === []) {
            return;
        }

        Mail::to($destinataires)->send(new AlertesSupervision($ouvertes, $resolues));
        Alerte::whereKey($ouvertes->pluck('id'))->update(['notifiee_le' => now()]);
    }

    /** @return list<string> le réglage « alertes_destinataires », sinon tous les admins actifs */
    public static function destinataires(): array
    {
        $reglage = collect(explode(',', (string) Parametre::valeur('alertes_destinataires')))->map(fn ($e) => trim($e))->filter()->values()->all();

        return $reglage !== [] ? $reglage : Admin::where('actif', true)->pluck('email')->all();
    }

    private static function date(?CarbonInterface $date): string
    {
        return $date?->setTimezone('Europe/Paris')->format('d/m à H:i') ?? '?';
    }
}
