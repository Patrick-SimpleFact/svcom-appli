<?php

namespace App\Filament\Resources\Collectes;

use App\Enums\StatutCollecte;
use App\Filament\Resources\Collectes\Pages\ListCollectes;
use App\Models\Collecte;
use App\Models\Source;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Chaque passage de collecte (F7.9) : en lecture seule.
 */
class CollecteResource extends Resource
{
    protected static ?string $model = Collecte::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = 'Collecte';

    protected static ?string $modelLabel = 'collecte';

    protected static ?string $pluralModelLabel = 'collectes';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('source'))
            ->defaultSort('id', 'desc')
            ->poll('10s')
            ->columns([
                TextColumn::make('debut')->label('Début')->dateTime('d/m/Y H:i:s', 'Europe/Paris'),
                TextColumn::make('source.nom')->label('Source'),
                TextColumn::make('statut')->badge(),
                TextColumn::make('essai')->label('Essai')->formatStateUsing(fn (int $state): string => "{$state} / 4"),
                TextColumn::make('duree')->label('Durée')
                    ->state(fn (Collecte $record): string => $record->fin ? max(1, (int) round($record->debut->diffInSeconds($record->fin))).' s' : '…'),
                TextColumn::make('version_detectee')->label('Version')->placeholder('—')->toggleable(),
                TextColumn::make('nb_recus')->label('Annonces lues')->numeric(),
                TextColumn::make('nb_illisibles')->label('Illisibles')->numeric(),
                TextColumn::make('nb_retenus')->label('Gardées')->numeric(),
                TextColumn::make('nb_exclus')->label('Exclues')->numeric(),
                TextColumn::make('nb_a_trier')->label('À trier')->numeric(),
                TextColumn::make('nb_hors_horizon')->label('Hors horizon')->numeric()->toggleable(),
                TextColumn::make('nb_nouveaux')->label('Représentations nouvelles')->numeric(),
                TextColumn::make('nb_mis_a_jour')->label('Mises à jour')->numeric(),
                TextColumn::make('nb_retires')->label('Retirées')->numeric(),
                TextColumn::make('erreur')->wrap()->limit(120)->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('source_id')->label('Source')->options(fn () => Source::orderBy('nom')->pluck('nom', 'id')),
                SelectFilter::make('statut')->options(StatutCollecte::class),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListCollectes::route('/')];
    }
}
