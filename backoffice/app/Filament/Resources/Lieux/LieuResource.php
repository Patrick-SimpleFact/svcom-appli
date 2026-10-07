<?php

namespace App\Filament\Resources\Lieux;

use App\Filament\Resources\Lieux\Pages\CreateLieu;
use App\Filament\Resources\Lieux\Pages\EditLieu;
use App\Filament\Resources\Lieux\Pages\ListLieux;
use App\Filament\Resources\Lieux\RelationManagers\RepresentationsRelationManager;
use App\Filament\Resources\Lieux\Schemas\LieuForm;
use App\Filament\Resources\Lieux\Tables\LieuxTable;
use App\Models\Lieu;
use App\Support\Texte;
use BackedEnum;
use Closure;
use Filament\Forms\Components\Select;
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

    /** Choix du lieu à conserver lors d'une fusion : recherche sans accents parmi les lieux actifs, sauf celui à fusionner. */
    public static function champLieuAConserver(Closure $lieuAExclure): Select
    {
        $libelle = fn (Lieu $lieu): string => $lieu->nom.($lieu->ville ? " — {$lieu->ville->nom}" : '');

        return Select::make('conserve_id')
            ->label('Lieu à conserver')
            ->required()
            ->searchable()
            ->getSearchResultsUsing(fn (string $search): array => Lieu::actifs()
                ->whereKeyNot($lieuAExclure()?->getKey())
                ->where('nom_normalise', 'like', '%'.Texte::normaliser($search).'%')
                ->with('ville')
                ->limit(20)
                ->get()
                ->mapWithKeys(fn (Lieu $lieu) => [$lieu->id => $libelle($lieu)])
                ->all())
            ->getOptionLabelUsing(fn ($value): ?string => ($lieu = Lieu::find($value)) ? $libelle($lieu) : null);
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
