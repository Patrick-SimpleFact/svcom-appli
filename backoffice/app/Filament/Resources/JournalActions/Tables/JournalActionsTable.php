<?php

namespace App\Filament\Resources\JournalActions\Tables;

use App\Enums\ActionJournal;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class JournalActionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                TextColumn::make('admin.nom')
                    ->label('Par')
                    ->placeholder('—'),
                TextColumn::make('action')
                    ->badge(),
                TextColumn::make('cible_type')
                    ->label('Élément')
                    ->formatStateUsing(fn (string $state, $record): string => class_basename($state).' n°'.$record->cible_id)
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->options(ActionJournal::class),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
