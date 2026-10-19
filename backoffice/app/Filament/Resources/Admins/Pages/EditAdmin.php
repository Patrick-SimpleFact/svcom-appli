<?php

namespace App\Filament\Resources\Admins\Pages;

use App\Filament\Resources\Admins\AdminResource;
use App\Filament\Resources\Admins\Schemas\AdminForm;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAdmin extends EditRecord
{
    protected static string $resource = AdminResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->hidden(fn (): bool => $this->record->is(auth()->user())), // pas son propre compte
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return AdminForm::enregistrer($data);
    }
}
