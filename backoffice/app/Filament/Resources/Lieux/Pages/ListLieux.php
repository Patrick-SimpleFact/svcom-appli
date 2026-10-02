<?php

namespace App\Filament\Resources\Lieux\Pages;

use App\Filament\Resources\Lieux\LieuResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLieux extends ListRecords
{
    protected static string $resource = LieuResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
