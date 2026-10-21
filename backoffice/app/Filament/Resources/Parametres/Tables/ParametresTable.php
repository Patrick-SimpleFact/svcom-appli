<?php

namespace App\Filament\Resources\Parametres\Tables;

use App\Enums\TypeParametre;
use App\Models\Parametre;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class ParametresTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultGroup(Group::make('groupe')->label('')->collapsible())
            ->defaultSort('id')
            ->paginated(false)
            ->columns([
                TextColumn::make('libelle')
                    ->label('Réglage')
                    ->description(fn (Parametre $record): ?string => $record->description)
                    ->wrap(),
                TextColumn::make('valeur')
                    ->label('Valeur')
                    ->state(fn (Parametre $record): string => self::afficher($record))
                    ->badge(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function afficher(Parametre $parametre): string
    {
        return match ($parametre->type) {
            TypeParametre::Booleen => $parametre->valeur ? 'Oui' : 'Non',
            TypeParametre::ListeEntiers => implode(' · ', (array) $parametre->valeur),
            TypeParametre::Decimal => str_replace('.', ',', (string) $parametre->valeur),
            default => (string) $parametre->valeur,
        };
    }
}
