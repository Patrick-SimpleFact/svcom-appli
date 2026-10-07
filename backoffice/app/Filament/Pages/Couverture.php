<?php

namespace App\Filament\Pages;

use App\Actions\MesurerCouverture;
use App\Filament\Widgets\CouvertureVilles;
use App\Filament\Widgets\EvolutionCouverture;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Tableau de bord de couverture des villes pilotes (F7.13) : la « condition d'existence » de l'app (§11), ville par ville.
 */
class Couverture extends Dashboard
{
    protected static string $routePath = '/couverture';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 0;

    public static function getNavigationLabel(): string
    {
        return 'Couverture';
    }

    public function getTitle(): string
    {
        return 'Couverture des villes pilotes';
    }

    /** Une ville pilote ajoutée (ou pas encore mesurée aujourd'hui) est mesurée à l'ouverture de la page. */
    public function mount(): void
    {
        app(MesurerCouverture::class)->enregistrer(seulementManquantes: true);
    }

    public function getWidgets(): array
    {
        return [CouvertureVilles::class, EvolutionCouverture::class];
    }

    public function getColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('mesurer')->label('Mesurer maintenant')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->tooltip('Fait automatiquement toutes les heures')
                ->action(function (): void {
                    $nombre = app(MesurerCouverture::class)->enregistrer();
                    Notification::make()->success()->title("{$nombre} ville(s) mesurée(s)")->send();
                    $this->dispatch('couverture-mesuree');
                }),
        ];
    }
}
