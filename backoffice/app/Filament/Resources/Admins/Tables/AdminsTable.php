<?php

namespace App\Filament\Resources\Admins\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AdminsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nom')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable(),
                IconColumn::make('actif')
                    ->boolean(),
                IconColumn::make('app_authentication_secret')
                    ->label('Double authentification')
                    ->state(fn ($record): bool => filled($record->app_authentication_secret))
                    ->boolean(),
                TextColumn::make('derniere_connexion_le')
                    ->label('Dernière connexion')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Jamais'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
