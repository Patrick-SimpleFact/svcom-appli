<?php

namespace App\Filament\Resources\Parametres;

use App\Filament\Resources\Parametres\Pages\EditParametre;
use App\Filament\Resources\Parametres\Pages\ListParametres;
use App\Filament\Resources\Parametres\Schemas\ParametreForm;
use App\Filament\Resources\Parametres\Tables\ParametresTable;
use App\Models\Parametre;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Réglages de l'app et de la collecte (F7.12) : la liste est fixe, seules les valeurs changent.
 */
class ParametreResource extends Resource
{
    protected static ?string $model = Parametre::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $modelLabel = 'réglage';

    protected static ?string $pluralModelLabel = 'réglages';

    protected static ?string $recordTitleAttribute = 'libelle';

    public static function form(Schema $schema): Schema
    {
        return ParametreForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ParametresTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListParametres::route('/'),
            'edit' => EditParametre::route('/{record}/edit'),
        ];
    }
}
