<?php

namespace App\Filament\Resources\Parametres\Pages;

use App\Filament\Resources\Parametres\ParametreResource;
use Filament\Resources\Pages\EditRecord;

class EditParametre extends EditRecord
{
    protected static string $resource = ParametreResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
