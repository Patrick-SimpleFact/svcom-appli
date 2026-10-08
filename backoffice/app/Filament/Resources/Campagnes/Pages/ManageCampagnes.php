<?php

namespace App\Filament\Resources\Campagnes\Pages;

use App\Filament\Resources\Campagnes\CampagneResource;
use App\Filament\Widgets\StatistiquesSuggestions;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCampagnes extends ManageRecords
{
    protected static string $resource = CampagneResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nouvelle campagne')];
    }

    protected function getHeaderWidgets(): array
    {
        return [StatistiquesSuggestions::class];
    }
}
