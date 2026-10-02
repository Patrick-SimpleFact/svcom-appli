<?php

namespace App\Filament\Resources\Villes\Tables;

use App\Support\Texte;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VillesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('population', 'desc')
            ->searchPlaceholder('Nom, code postal ou code INSEE')
            ->columns([
                TextColumn::make('nom')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('nom_normalise', 'like', '%'.Texte::normaliser($search).'%')
                        ->orWhere('code_insee', $search)
                        ->orWhereRaw('codes_postaux::text like ?', ['%"'.$search.'%'])),
                TextColumn::make('departement')
                    ->label('Dépt'),
                TextColumn::make('codes_postaux')
                    ->label('Codes postaux')
                    ->formatStateUsing(fn ($state): string => is_array($state) ? implode(', ', $state) : (string) $state),
                TextColumn::make('population')
                    ->numeric(thousandsSeparator: ' ')
                    ->sortable(),
                TextColumn::make('fuseau_horaire')
                    ->label('Fuseau')
                    ->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('est_pilote')
                    ->label('Ville pilote'),
            ])
            ->filters([
                TernaryFilter::make('est_pilote')
                    ->label('Ville pilote'),
            ]);
    }
}
