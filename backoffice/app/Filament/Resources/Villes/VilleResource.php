<?php

namespace App\Filament\Resources\Villes;

use App\Filament\Resources\Villes\Pages\ListVilles;
use App\Filament\Resources\Villes\Tables\VillesTable;
use App\Models\Ville;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Communes : importées automatiquement ; seul le choix « ville pilote » se modifie ici.
 */
class VilleResource extends Resource
{
    protected static ?string $model = Ville::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Référentiels';

    protected static ?string $modelLabel = 'ville';

    protected static ?string $pluralModelLabel = 'villes';

    protected static ?string $recordTitleAttribute = 'nom';

    public static function table(Table $table): Table
    {
        return VillesTable::configure($table);
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
            'index' => ListVilles::route('/'),
        ];
    }
}
