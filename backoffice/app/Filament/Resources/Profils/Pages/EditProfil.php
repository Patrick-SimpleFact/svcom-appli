<?php

namespace App\Filament\Resources\Profils\Pages;

use App\Filament\Resources\Profils\ProfilResource;
use App\Filament\Support\MenusProfil;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProfil extends EditRecord
{
    protected static string $resource = ProfilResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => ! $this->record->super_admin && $this->record->admins()->doesntExist()),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // « Super Admin » ne se modifie pas : ni son nom, ni ses menus.
        return $this->record->super_admin ? [] : MenusProfil::reunir($data);
    }
}
