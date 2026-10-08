<?php

namespace App\Filament\Resources\Annonceurs\Pages;

use App\Filament\Resources\Annonceurs\AnnonceurResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAnnonceurs extends ManageRecords
{
    protected static string $resource = AnnonceurResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nouvel annonceur')];
    }
}
