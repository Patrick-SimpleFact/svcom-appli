<?php

namespace App\Filament\Resources\Profils\Pages;

use App\Filament\Resources\Profils\ProfilResource;
use App\Filament\Support\MenusProfil;
use Filament\Resources\Pages\CreateRecord;

class CreateProfil extends CreateRecord
{
    protected static string $resource = ProfilResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return MenusProfil::reunir($data);
    }
}
