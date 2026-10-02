<?php

namespace App\Filament\Resources\ReglesFiltrage\Pages;

use App\Filament\Resources\ReglesFiltrage\RegleFiltrageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageReglesFiltrage extends ManageRecords
{
    protected static string $resource = RegleFiltrageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Ajouter un mot'),
        ];
    }
}
