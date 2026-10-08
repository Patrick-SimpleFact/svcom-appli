<?php

namespace App\Filament\Widgets;

use App\Enums\ChoixSuggestion;
use App\Models\AffichageSuggestion;
use App\Models\Appareil;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Indicateurs des suggestions (F6.5) sur 30 jours : acceptation de la question, désactivations,
 * pertinence (« Voir le spectacle ») des suggestions automatiques et sponsorisées.
 */
class StatistiquesSuggestions extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $choix = Appareil::selectRaw('suggestion_choix, count(*) as n')->groupBy('suggestion_choix')->pluck('n', 'suggestion_choix');
        $oui = (int) ($choix[ChoixSuggestion::Oui->value] ?? 0);
        $non = (int) ($choix[ChoixSuggestion::NonMerci->value] ?? 0);
        $desactive = (int) ($choix[ChoixSuggestion::Desactive->value] ?? 0);

        $affichages = AffichageSuggestion::where('affiche_le', '>=', now()->subDays(30))
            ->selectRaw('campagne_id is not null as sponsorise, count(*) as n, count(*) filter (where clic_fiche) as fiches, count(*) filter (where clic_billetterie) as billetteries')
            ->groupByRaw('campagne_id is not null')->get()->keyBy(fn ($l) => $l->sponsorise ? 'sponsorise' : 'auto');

        $ligne = function (string $type) use ($affichages): string {
            $l = $affichages->get($type);
            $n = (int) ($l->n ?? 0);

            return $n === 0 ? 'aucune' : "{$n} montrée(s), « Voir » ".round(100 * $l->fiches / $n).' %, billetterie '.round(100 * $l->billetteries / $n).' %';
        };

        return [
            Stat::make('Ont accepté', number_format($oui, 0, ',', ' '))
                ->description($oui + $non > 0 ? round(100 * $oui / ($oui + $non)).' % des réponses à la question' : 'Pas encore de réponse'),
            Stat::make('Désactivées ensuite', number_format($desactive, 0, ',', ' '))->description('« Ne plus me proposer » ou Profil'),
            Stat::make('Automatiques (30 j)', (string) (int) ($affichages->get('auto')->n ?? 0))->description($ligne('auto')),
            Stat::make('Sponsorisées (30 j)', (string) (int) ($affichages->get('sponsorise')->n ?? 0))->description($ligne('sponsorise')),
        ];
    }
}
