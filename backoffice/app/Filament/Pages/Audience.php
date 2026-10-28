<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ActionsCommunication\ActionCommunicationResource;
use App\Filament\Resources\Appareils\AppareilResource;
use App\Filament\Support\OuvertSelonProfil;
use App\Filament\Widgets\Audience\AppareilsActifsParJour;
use App\Filament\Widgets\Audience\ComptesCreesParJour;
use App\Filament\Widgets\Audience\CompteursAudience;
use App\Filament\Widgets\Audience\FideliteNouveauxAppareils;
use App\Filament\Widgets\Audience\NouveauxAppareilsParHeure;
use App\Filament\Widgets\Audience\NouveauxAppareilsParJour;
use App\Filament\Widgets\Audience\ParcoursParJour;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Audience de l'app : installations, ouvertures, fidélité, parcours et comptes, avec les actions de communication
 * en repères sur les courbes, pour voir l'effet de chaque action. Appareils de test écartés.
 */
class Audience extends Dashboard
{
    use HasFiltersForm;
    use OuvertSelonProfil;

    /** À chaque ouverture, on repart d'aujourd'hui (le jour choisi reste dans l'adresse, pour la partager). */
    public function persistsFiltersInSession(): bool
    {
        return false;
    }

    protected static string $routePath = '/audience';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Statistiques';

    protected static ?int $navigationSort = 0;

    public static function getNavigationLabel(): string
    {
        return 'Audience de l’app';
    }

    public function getTitle(): string
    {
        return 'Audience de l’app';
    }

    public function getSubheading(): string
    {
        return 'Heure de Paris. Un appareil est une installation (une réinstallation compte comme un nouvel appareil) ; les appareils de test et le compte de démonstration des boutiques sont écartés. Les triangles violets sont vos actions de communication.';
    }

    /** Le jour de référence : toutes les courbes, le tableau et les compteurs s'arrêtent à ce jour. */
    public function filtersForm(Schema $schema): Schema
    {
        $aujourdhui = now('Europe/Paris')->toDateString();

        return $schema->components([
            DatePicker::make('jour')->label('Jour de référence')->default($aujourdhui)->maxDate($aujourdhui)
                ->native(false)->displayFormat('d/m/Y')->closeOnDateSelection()
                ->helperText('Les courbes, le tableau et les chiffres s’arrêtent à ce jour (aujourd’hui par défaut).'),
        ])->columns(['default' => 1, 'md' => 3]);
    }

    public function getWidgets(): array
    {
        return [
            CompteursAudience::class,
            NouveauxAppareilsParJour::class,
            NouveauxAppareilsParHeure::class,
            AppareilsActifsParJour::class,
            ComptesCreesParJour::class,
            FideliteNouveauxAppareils::class,
            ParcoursParJour::class,
        ];
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('action')->label('Noter une action de communication')->icon(Heroicon::OutlinedMegaphone)
                ->url(fn () => ActionCommunicationResource::getUrl()),
            Action::make('tests')->label('Appareils de test')->icon(Heroicon::OutlinedDevicePhoneMobile)->color('gray')
                ->url(fn () => AppareilResource::getUrl()),
        ];
    }
}
