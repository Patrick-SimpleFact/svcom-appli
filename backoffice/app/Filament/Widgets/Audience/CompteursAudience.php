<?php

namespace App\Filament\Widgets\Audience;

use App\Statistiques\Audience;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Les chiffres au jour de référence en tête de la page Audience (appareils de test et compte des boutiques écartés). */
class CompteursAudience extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $audience = app(Audience::class);
        $reference = $audience->referenceFiltre($this->pageFilters['jour'] ?? null);
        $aujourdhui = $reference->equalTo($audience->debutDuJour());
        $le = $aujourdhui ? 'aujourd’hui' : 'le '.$reference->translatedFormat('d/m');
        $c = $audience->compteurs($reference);
        $nombre = fn (int $n) => number_format($n, 0, ',', ' ');

        return [
            Stat::make($aujourdhui ? 'Appareils (installations)' : 'Appareils au '.$reference->translatedFormat('d/m'), $nombre($c['total']))
                ->description('iOS '.$nombre($c['ios']).' · Android '.$nombre($c['android']))->icon('heroicon-o-device-phone-mobile'),
            Stat::make('Ouverts '.$le, $nombre($c['ouverts_jour']))
                ->description('dont '.$nombre($c['nouveaux_jour']).' nouveau(x)')->icon('heroicon-o-sun'),
            Stat::make('Actifs sur 7 jours', $nombre($c['actifs_7j']))
                ->description('sur 30 jours : '.$nombre($c['actifs_30j']))->icon('heroicon-o-calendar-days'),
            Stat::make('Revenus au moins une fois', $nombre($c['revenus']))
                ->description(($c['total'] > 0 ? round(100 * $c['revenus'] / $c['total']).' % des appareils' : '—').', un autre jour que l’installation')->icon('heroicon-o-arrow-path'),
            Stat::make('Comptes', $nombre($c['comptes']))
                ->description('créés '.$le.' : '.$nombre($c['comptes_jour']))->icon('heroicon-o-user-circle'),
        ];
    }
}
