<?php

namespace App\Filament\Resources\Spectacles\RelationManagers;

use App\Models\Representation;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RepresentationsRelationManager extends RelationManager
{
    protected static string $relationship = 'representations';

    protected static ?string $title = 'Représentations à venir';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['lieu.ville'])->whereDate('date_locale', '>=', today()))
            ->defaultSort('date_locale')
            ->columns([
                TextColumn::make('date_locale')->label('Jour')->date('D d/m/Y'),
                TextColumn::make('debut')->label('Heure')
                    ->state(fn (Representation $r): string => $r->debut?->setTimezone($r->lieu?->fuseau_horaire ?? 'Europe/Paris')->format('H:i') ?? 'à confirmer'),
                TextColumn::make('lieu.nom')->label('Lieu')->wrap()->description(fn (Representation $r): ?string => $r->salle),
                TextColumn::make('lieu.ville.nom')->label('Ville'),
                TextColumn::make('statut')->badge(),
            ]);
    }
}
