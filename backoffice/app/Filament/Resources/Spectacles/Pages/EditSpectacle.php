<?php

namespace App\Filament\Resources\Spectacles\Pages;

use App\Filament\Resources\Spectacles\SpectacleResource;
use App\Filament\Support\Masquage;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

/** Correction d'un spectacle : chaque champ modifié est verrouillé contre la collecte (F7.8). */
class EditSpectacle extends EditRecord
{
    protected static string $resource = SpectacleResource::class;

    protected static ?string $title = 'Corriger le spectacle';

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), ...Masquage::boutons('masque', 'Le spectacle et toutes ses séances disparaissent de l’app.')];
    }

    protected function getRedirectUrl(): string
    {
        return SpectacleResource::getUrl('view', ['record' => $this->record]);
    }
}
