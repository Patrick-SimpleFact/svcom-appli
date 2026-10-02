<?php

namespace App\Filament\Resources\Lieux\Tables;

use App\Enums\PrecisionPosition;
use App\Enums\TypeLieu;
use App\Support\Texte;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LieuxTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('ville'))
            ->defaultSort('nom')
            ->searchPlaceholder('Nom, adresse ou ville')
            ->columns([
                TextColumn::make('nom')
                    ->description(fn ($record): ?string => $record->label)
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q) => $q
                        ->where('nom_normalise', 'like', '%'.Texte::normaliser($search).'%')
                        ->orWhere('adresse', 'ilike', '%'.$search.'%')
                        ->orWhereHas('ville', fn (Builder $v) => $v->where('nom_normalise', 'like', Texte::normaliser($search).'%'))))
                    ->sortable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('ville.nom')->label('Ville'),
                TextColumn::make('precision_position')->label('Position')->badge(),
                TextColumn::make('jauge')->numeric()->placeholder('—')->toggleable(),
                IconColumn::make('masque')->label('Masqué')->boolean()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->options(TypeLieu::class)->multiple(),
                SelectFilter::make('precision_position')->label('Précision de la position')->options(PrecisionPosition::class),
                TernaryFilter::make('ville_pilote')
                    ->label('Villes pilotes')
                    ->queries(
                        true: fn (Builder $q) => $q->whereHas('ville', fn (Builder $v) => $v->where('est_pilote', true)),
                        false: fn (Builder $q) => $q->whereDoesntHave('ville', fn (Builder $v) => $v->where('est_pilote', true)),
                    ),
                TernaryFilter::make('fusionnes')
                    ->label('Lieux fusionnés')
                    ->placeholder('Masquer les doublons fusionnés')
                    ->trueLabel('Seulement les doublons fusionnés')
                    ->falseLabel('Tous')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('fusionne_dans_id'),
                        false: fn (Builder $q) => $q,
                        blank: fn (Builder $q) => $q->whereNull('fusionne_dans_id'),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
