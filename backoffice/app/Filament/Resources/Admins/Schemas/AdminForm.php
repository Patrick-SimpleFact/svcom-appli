<?php

namespace App\Filament\Resources\Admins\Schemas;

use App\Filament\Support\MenusProfil;
use App\Models\Admin;
use App\Models\Profil;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AdminForm
{
    /** Valeur du choix « Libre » (compte sans modèle, avec ses propres menus). */
    public const LIBRE = 'libre';

    /** Champ « modèle » du formulaire → colonnes profil_id et menus ; champ absent (son propre compte) : rien ne change. */
    public static function enregistrer(array $data): array
    {
        if (! array_key_exists('profil_id', $data)) {
            return array_diff_key(MenusProfil::reunir($data), ['menus' => true]);
        }

        $libre = $data['profil_id'] === self::LIBRE;
        $data = MenusProfil::reunir($data);

        return [...$data, 'profil_id' => $libre ? null : (int) $data['profil_id'], 'menus' => $libre ? $data['menus'] : null];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nom')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('E-mail')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->label('Mot de passe')
                    ->password()
                    ->revealable()
                    ->minLength(12)
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText('12 caractères minimum. Laisser vide pour ne pas le changer.'),
                Select::make('profil_id')
                    ->label('Modèle de profil')
                    ->options(fn (): array => Profil::orderByDesc('super_admin')->orderBy('nom')->pluck('nom', 'id')->all() + [self::LIBRE => 'Libre (choisir les menus)'])
                    ->required()
                    ->live()
                    // Compte sans modèle : « Libre ».
                    ->afterStateHydrated(fn (Select $c, ?Admin $record) => $record && $c->state($record->profil_id ?? self::LIBRE))
                    // Son propre compte : ni changer de modèle, ni se désactiver (on ne s'enferme pas dehors).
                    ->disabled(fn (?Admin $record): bool => $record?->is(auth()->user()) ?? false)
                    ->helperText('Avec un modèle, modifier le modèle change aussitôt les menus du compte. « Libre » : les menus cochés ci-dessous, pour ce compte seulement.'),
                ...MenusProfil::sections(fn (Get $get): bool => $get('profil_id') === self::LIBRE),
                Toggle::make('actif')
                    ->default(true)
                    ->disabled(fn (?Admin $record): bool => $record?->is(auth()->user()) ?? false)
                    ->helperText('Un compte inactif ne peut plus se connecter.'),
            ]);
    }
}
