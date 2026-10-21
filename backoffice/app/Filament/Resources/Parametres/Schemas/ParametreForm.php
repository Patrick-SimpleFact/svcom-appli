<?php

namespace App\Filament\Resources\Parametres\Schemas;

use App\Enums\TypeParametre;
use App\Models\Parametre;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

class ParametreForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components(fn (?Parametre $record): array => [
                Text::make(fn (): string => $record?->description ?? ''),
                self::champValeur($record?->type)
                    ->label($record?->libelle ?? 'Valeur'),
            ]);
    }

    private static function champValeur(?TypeParametre $type)
    {
        return match ($type) {
            TypeParametre::Entier => TextInput::make('valeur')->integer()->required()->minValue(0),
            TypeParametre::Decimal => TextInput::make('valeur')->numeric()->required()->minValue(0)->maxValue(1)->step(0.05),
            TypeParametre::Booleen => Toggle::make('valeur'),
            TypeParametre::ListeEntiers => TagsInput::make('valeur')
                ->required()
                ->nestedRecursiveRules(['integer', 'min:0'])
                ->separator(',')
                ->helperText('Saisir chaque nombre puis Entrée.')
                ->dehydrateStateUsing(fn ($state): array => array_values(array_map('intval', (array) $state))),
            default => TextInput::make('valeur')->required(),
        };
    }
}
