<?php

namespace App\Filament\Resources\CorrespondancesGenres\Pages;

use App\Filament\Resources\CorrespondancesGenres\CorrespondanceGenreResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCorrespondancesGenres extends ManageRecords
{
    protected static string $resource = CorrespondanceGenreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Ajouter une correspondance')
                ->using(fn (array $data) => CorrespondanceGenreResource::enregistrer($data)),
        ];
    }
}
