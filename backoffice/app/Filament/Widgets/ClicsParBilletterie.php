<?php

namespace App\Filament\Widgets;

use App\Models\ClicSortant;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Clics comptés : aujourd'hui, sur 7 jours, et par billetterie sur 7 jours (F7.13 bis).
 */
class ClicsParBilletterie extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $comptes = ClicSortant::where('compte', true);
        $parSource = (clone $comptes)->where('horodatage', '>=', now()->subDays(7))
            ->join('sources', 'sources.id', '=', 'clics_sortants.source_id')
            ->selectRaw('sources.nom, count(*) as n')->groupBy('sources.nom')->orderByDesc('n')->limit(3)->pluck('n', 'nom');

        return [
            Stat::make('Clics aujourd’hui', (clone $comptes)->where('horodatage', '>=', today('Europe/Paris')->utc())->count()),
            Stat::make('Clics sur 7 jours', (clone $comptes)->where('horodatage', '>=', now()->subDays(7))->count())
                ->description($parSource->isEmpty() ? 'Aucun clic' : $parSource->map(fn ($n, $nom) => "{$nom} : {$n}")->implode(' · ')),
            Stat::make('Non comptés (7 jours)', ClicSortant::where('compte', false)->where('horodatage', '>=', now()->subDays(7))->count())
                ->description('répétés en 30 min, robots, tests'),
        ];
    }
}
