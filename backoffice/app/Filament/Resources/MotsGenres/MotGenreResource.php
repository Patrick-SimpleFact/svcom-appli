<?php

namespace App\Filament\Resources\MotsGenres;

use App\Filament\Resources\MotsGenres\Pages\ManageMotsGenres;
use App\Models\MotGenre;
use App\Support\Texte;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Mots du titre ou de la description qui donnent un genre, ou le marqueur « Jeune public » (COLLECTE §6, F7.6).
 * Pris en compte dès la collecte suivante.
 */
class MotGenreResource extends Resource
{
    protected static ?string $model = MotGenre::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Collecte';

    protected static ?string $navigationLabel = 'Mots de genre';

    protected static ?string $modelLabel = 'mot de genre';

    protected static ?string $pluralModelLabel = 'mots de genre';

    protected static ?string $slug = 'mots-de-genre';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('mot')->label('Mot ou expression')->required()->maxLength(100)
                ->helperText('Cherché dans le titre, puis la description, sans accents ni majuscules, au singulier comme au pluriel. Le mot le plus long l’emporte (« comédie musicale » avant « comédie »).')
                ->dehydrateStateUsing(fn (?string $state): string => Texte::normaliser($state))
                ->rule(fn (?MotGenre $record): Closure => function (string $attribut, mixed $valeur, Closure $echec) use ($record) {
                    $mot = Texte::normaliser($valeur);

                    if ($mot === '') {
                        $echec('Le mot doit contenir au moins une lettre ou un chiffre.');
                    } elseif (MotGenre::where('mot', $mot)->whereKeyNot($record?->getKey())->exists()) {
                        $echec("« {$mot} » est déjà dans la liste.");
                    }
                }),
            Select::make('genre_id')->label('Genre')->relationship('genre', 'libelle', fn ($query) => $query->orderBy('ordre'))
                ->placeholder('Aucun (marqueur « Jeune public » seulement)')
                ->required(fn (Get $get): bool => ! $get('jeune_public')),
            Toggle::make('jeune_public')->label('Marque aussi le spectacle « Jeune public »')->live(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('mot')
            ->paginated([50, 100, 'all'])
            ->columns([
                TextColumn::make('mot')->label('Mot ou expression')->searchable()->sortable(),
                TextColumn::make('genre.libelle')->label('Genre')->badge()->placeholder('—')->sortable(),
                IconColumn::make('jeune_public')->label('Jeune public')->boolean(),
                ToggleColumn::make('actif')->label('Actif'),
            ])
            ->filters([
                SelectFilter::make('genre_id')->label('Genre')->relationship('genre', 'libelle'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMotsGenres::route('/')];
    }
}
