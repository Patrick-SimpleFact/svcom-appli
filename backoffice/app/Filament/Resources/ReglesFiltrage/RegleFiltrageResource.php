<?php

namespace App\Filament\Resources\ReglesFiltrage;

use App\Enums\TypeRegleFiltrage;
use App\Filament\Resources\ReglesFiltrage\Pages\ManageReglesFiltrage;
use App\Models\RegleFiltrage;
use App\Support\Texte;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Listes de mots du filtre « spectacle vivant » (F7.4) : prises en compte dès la collecte suivante.
 */
class RegleFiltrageResource extends Resource
{
    protected static ?string $model = RegleFiltrage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFunnel;

    protected static string|UnitEnum|null $navigationGroup = 'Collecte';

    protected static ?string $navigationLabel = 'Mots de tri';

    protected static ?string $modelLabel = 'mot de tri';

    protected static ?string $pluralModelLabel = 'mots de tri';

    protected static ?string $slug = 'mots-de-tri';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            ToggleButtons::make('type')->label('Effet')->options(TypeRegleFiltrage::class)->inline()->required()
                ->default(TypeRegleFiltrage::Exclure->value)
                ->colors([TypeRegleFiltrage::Exclure->value => 'danger', TypeRegleFiltrage::Inclure->value => 'success']),
            TextInput::make('mot')->label('Mot ou expression')->required()->maxLength(100)
                ->helperText('Comparé sans accents ni majuscules, au singulier comme au pluriel (« bibliothèque » trouve aussi « Bibliothèques »).')
                ->dehydrateStateUsing(fn (?string $state): string => Texte::normaliser($state))
                ->rule(fn (Get $get, ?RegleFiltrage $record): Closure => function (string $attribut, mixed $valeur, Closure $echec) use ($get, $record) {
                    $mot = Texte::normaliser($valeur);

                    if ($mot === '') {
                        $echec('Le mot doit contenir au moins une lettre ou un chiffre.');
                    } elseif (RegleFiltrage::where('type', $get('type'))->where('mot', $mot)->whereKeyNot($record?->getKey())->exists()) {
                        $echec("« {$mot} » est déjà dans cette liste.");
                    }
                }),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('mot')
            ->paginated([50, 100, 'all'])
            ->columns([
                TextColumn::make('mot')->label('Mot ou expression')->searchable()->sortable(),
                TextColumn::make('type')->label('Effet')->badge()
                    ->color(fn (TypeRegleFiltrage $state): string => $state === TypeRegleFiltrage::Exclure ? 'danger' : 'success'),
                ToggleColumn::make('actif')->label('Actif'),
                TextColumn::make('updated_at')->label('Modifié le')->dateTime('d/m/Y H:i', 'Europe/Paris')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->label('Effet')->options(TypeRegleFiltrage::class),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageReglesFiltrage::route('/'),
        ];
    }
}
