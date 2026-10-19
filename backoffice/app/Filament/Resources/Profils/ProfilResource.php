<?php

namespace App\Filament\Resources\Profils;

use App\Filament\Resources\Profils\Pages\CreateProfil;
use App\Filament\Resources\Profils\Pages\EditProfil;
use App\Filament\Resources\Profils\Pages\ListProfils;
use App\Filament\Support\MenusProfil;
use App\Filament\Support\ReserveAuSuperAdmin;
use App\Models\Profil;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Modèles de profil (réservé aux super admins) : les menus ouverts aux comptes qui suivent chaque modèle.
 * « Super Admin » ouvre tout et ne se modifie pas.
 */
class ProfilResource extends Resource
{
    use ReserveAuSuperAdmin;

    protected static ?string $model = Profil::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Modèles de profil';

    protected static ?string $modelLabel = 'modèle de profil';

    protected static ?string $pluralModelLabel = 'modèles de profil';

    protected static ?string $recordTitleAttribute = 'nom';

    protected static ?string $slug = 'modeles-profil';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('nom')->required()->maxLength(100)->unique(ignoreRecord: true)
                ->disabled(fn (?Profil $record): bool => (bool) $record?->super_admin),
            Text::make('Ce modèle ouvre tous les menus, y compris les administrateurs et les modèles de profil. Il ne se modifie pas.')
                ->visible(fn (?Profil $record): bool => (bool) $record?->super_admin),
            ...MenusProfil::sections(fn (?Profil $record): bool => ! $record?->super_admin),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nom')->searchable()->weight('bold'),
                TextColumn::make('menus')->label('Menus ouverts')
                    ->state(fn (Profil $record): string => $record->super_admin ? 'Tous' : (implode(', ', MenusProfil::libelles($record->menus ?? [])) ?: 'Aucun'))
                    ->wrap(),
                TextColumn::make('admins_count')->label('Comptes')->counts('admins'),
            ])
            ->defaultSort('nom')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (Profil $record): bool => ! $record->super_admin && $record->admins()->doesntExist()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProfils::route('/'),
            'create' => CreateProfil::route('/create'),
            'edit' => EditProfil::route('/{record}/edit'),
        ];
    }
}
