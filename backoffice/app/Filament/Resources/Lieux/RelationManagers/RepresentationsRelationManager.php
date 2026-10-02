<?php

namespace App\Filament\Resources\Lieux\RelationManagers;

use App\Models\Representation;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Programme à venir d'un lieu (lecture seule ; la collecte l'alimente).
 */
class RepresentationsRelationManager extends RelationManager
{
    protected static string $relationship = 'representations';

    protected static ?string $title = 'Programme à venir';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['spectacle', 'genre'])->whereDate('date_locale', '>=', today()))
            ->defaultSort('date_locale')
            ->columns([
                TextColumn::make('date_locale')->label('Jour')->date('D d/m/Y')->sortable(),
                TextColumn::make('debut')->label('Heure')
                    ->state(fn (Representation $r): string => $r->debut?->setTimezone($this->getOwnerRecord()->fuseau_horaire)->format('H:i') ?? 'à confirmer'),
                TextColumn::make('spectacle.titre')->label('Spectacle')->wrap(),
                TextColumn::make('genre.libelle')->label('Genre')->badge(),
                TextColumn::make('prix_min')->label('Dès')->money('EUR', locale: 'fr')->placeholder('—'),
                IconColumn::make('complet')->boolean(),
                TextColumn::make('statut')->badge(),
            ]);
    }
}
