<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;

/**
 * Page « Profil » : le nom est stocké dans la colonne `nom` (et non `name`).
 */
class EditProfile extends BaseEditProfile
{
    protected function getNameFormComponent(): Component
    {
        return TextInput::make('nom')
            ->label('Nom')
            ->required()
            ->maxLength(255)
            ->autofocus();
    }
}
