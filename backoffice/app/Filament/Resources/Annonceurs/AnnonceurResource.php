<?php

namespace App\Filament\Resources\Annonceurs;

use App\Filament\Resources\Annonceurs\Pages\ManageAnnonceurs;
use App\Models\Annonceur;
use App\Models\Lieu;
use App\Support\Texte;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Annonceurs des campagnes sponsorisées (F6.4) : théâtres et producteurs, vente manuelle (D5). */
class AnnonceurResource extends Resource
{
    protected static ?string $model = Annonceur::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = 'Suggestions';

    protected static ?string $navigationLabel = 'Annonceurs';

    protected static ?string $modelLabel = 'annonceur';

    protected static ?string $pluralModelLabel = 'annonceurs';

    protected static ?string $slug = 'annonceurs';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('nom')->required()->maxLength(200)->columnSpanFull(),
            TextInput::make('contact')->label('Personne à contacter')->maxLength(200),
            TextInput::make('email')->label('E-mail')->email()->maxLength(255),
            TextInput::make('telephone')->label('Téléphone')->tel()->maxLength(30),
            Select::make('lieu_id')->label('Lieu (s’il s’agit d’une salle)')->searchable()
                ->getSearchResultsUsing(fn (string $search): array => Lieu::with('ville')->where('nom_normalise', 'like', '%'.Texte::normaliser($search).'%')
                    ->whereNull('fusionne_dans_id')->limit(20)->get()->mapWithKeys(fn (Lieu $l) => [$l->id => "{$l->nom} ({$l->ville?->nom})"])->all())
                ->getOptionLabelUsing(fn ($value): ?string => ($l = Lieu::with('ville')->find($value)) ? "{$l->nom} ({$l->ville?->nom})" : null),
            Textarea::make('notes')->rows(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('nom')
            ->modifyQueryUsing(fn ($query) => $query->with('lieu')->withCount('campagnes'))
            ->columns([
                TextColumn::make('nom')->weight('bold')->searchable(),
                TextColumn::make('contact')->placeholder('—')->description(fn (Annonceur $record): ?string => $record->email),
                TextColumn::make('lieu.nom')->label('Lieu')->placeholder('—'),
                TextColumn::make('campagnes_count')->label('Campagnes'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()->hidden(fn (Annonceur $record): bool => $record->campagnes_count > 0)]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAnnonceurs::route('/')];
    }
}
