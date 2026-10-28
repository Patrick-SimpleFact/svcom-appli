<?php

namespace App\Filament\Resources\Appareils\Pages;

use App\Filament\Pages\Audience;
use App\Filament\Resources\Appareils\AppareilResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListAppareils extends ListRecords
{
    protected static string $resource = AppareilResource::class;

    public function getSubheading(): string
    {
        return 'Cochez « Appareil de test » pour vos téléphones et ceux des essais : ils sont écartés de la page Audience. Le compte de démonstration des boutiques est écarté d’office.';
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('audience')->label('Voir l’audience')->icon(Heroicon::OutlinedPresentationChartLine)->color('gray')->url(fn () => Audience::getUrl())];
    }
}
