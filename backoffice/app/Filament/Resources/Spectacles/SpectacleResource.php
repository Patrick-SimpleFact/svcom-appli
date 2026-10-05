<?php

namespace App\Filament\Resources\Spectacles;

use App\Filament\Resources\Spectacles\Pages\ListSpectacles;
use App\Filament\Resources\Spectacles\Pages\ViewSpectacle;
use App\Filament\Resources\Spectacles\RelationManagers\OffresRelationManager;
use App\Filament\Resources\Spectacles\RelationManagers\RepresentationsRelationManager;
use App\Models\Genre;
use App\Models\Spectacle;
use App\Support\Texte;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Catalogue des spectacles : en lecture seule à ce stade (alimenté par la collecte, bloc 2).
 */
class SpectacleResource extends Resource
{
    protected static ?string $model = Spectacle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?string $modelLabel = 'spectacle';

    protected static ?string $pluralModelLabel = 'spectacles';

    protected static ?string $recordTitleAttribute = 'titre';

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('titre')->columnSpanFull(),
            TextEntry::make('genre.libelle')->label('Genre')->badge(),
            TextEntry::make('classification_fine')->label('Classification')->placeholder('—'),
            IconEntry::make('jeune_public')->label('Jeune public')->boolean(),
            TextEntry::make('duree_minutes')->label('Durée')->suffix(' min')->placeholder('—'),
            TextEntry::make('description')->columnSpanFull()->placeholder('Aucune description'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('genre')
                ->withCount(['representations as a_venir' => fn (Builder $q) => $q->whereDate('date_locale', '>=', today())])
                ->withCount(['offres as seances_collectees' => fn (Builder $q) => $q->whereDate('date_locale', '>=', today())])
                ->withMin(['representations as prochaine' => fn (Builder $q) => $q->whereDate('date_locale', '>=', today())], 'date_locale'))
            ->defaultSort('prochaine')
            ->columns([
                TextColumn::make('titre')
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('titre_normalise', 'like', '%'.Texte::normaliser($search).'%')),
                TextColumn::make('genre.libelle')->label('Genre')->badge(),
                TextColumn::make('a_venir')->label('Représentations à venir')->numeric(),
                TextColumn::make('seances_collectees')->label('Séances collectées')->numeric()->sortable(),
                TextColumn::make('prochaine')->label('Prochaine')->date('d/m/Y')->sortable()->placeholder('—'),
                TextColumn::make('demo')->label('')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'Démo' : '')->color('gray'),
            ])
            ->filters([
                SelectFilter::make('genre_id')->label('Genre')->options(fn () => Genre::orderBy('ordre')->pluck('libelle', 'id')),
                TernaryFilter::make('demo')->label('Données de démonstration'),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [RepresentationsRelationManager::class, OffresRelationManager::class];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
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
            'index' => ListSpectacles::route('/'),
            'view' => ViewSpectacle::route('/{record}'),
        ];
    }
}
