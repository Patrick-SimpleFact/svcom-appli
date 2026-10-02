<?php

namespace App\Filament\Resources\Lieux;

use App\Filament\Resources\Lieux\Pages\CreateLieu;
use App\Filament\Resources\Lieux\Pages\EditLieu;
use App\Filament\Resources\Lieux\Pages\ListLieux;
use App\Filament\Resources\Lieux\RelationManagers\RepresentationsRelationManager;
use App\Filament\Resources\Lieux\Schemas\LieuForm;
use App\Filament\Resources\Lieux\Tables\LieuxTable;
use App\Models\Lieu;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class LieuResource extends Resource
{
    protected static ?string $model = Lieu::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Référentiels';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'lieux';

    protected static ?string $modelLabel = 'lieu';

    protected static ?string $pluralModelLabel = 'lieux';

    protected static ?string $recordTitleAttribute = 'nom';

    public static function form(Schema $schema): Schema
    {
        return LieuForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LieuxTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RepresentationsRelationManager::class,
        ];
    }

    /** Pas de suppression : on masque ou on fusionne (F7.5, F7.8). */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLieux::route('/'),
            'create' => CreateLieu::route('/create'),
            'edit' => EditLieu::route('/{record}/edit'),
        ];
    }
}
