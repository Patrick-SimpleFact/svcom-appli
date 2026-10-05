<?php

namespace App\Filament\Resources\MotsGenres\Pages;

use App\Filament\Resources\MotsGenres\MotGenreResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMotsGenres extends ManageRecords
{
    protected static string $resource = MotGenreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Ajouter un mot'),
        ];
    }
}
