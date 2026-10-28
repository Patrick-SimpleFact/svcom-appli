<?php

namespace App\Filament\Resources\ActionsCommunication\Pages;

use App\Filament\Pages\Audience;
use App\Filament\Resources\ActionsCommunication\ActionCommunicationResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageActionsCommunication extends ManageRecords
{
    protected static string $resource = ActionCommunicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nouvelle action'),
            Action::make('audience')->label('Voir l’audience')->icon(Heroicon::OutlinedPresentationChartLine)->color('gray')->url(fn () => Audience::getUrl()),
        ];
    }
}
