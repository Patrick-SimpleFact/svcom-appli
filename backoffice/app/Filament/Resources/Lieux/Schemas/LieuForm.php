<?php

namespace App\Filament\Resources\Lieux\Schemas;

use App\Enums\PrecisionPosition;
use App\Enums\TypeLieu;
use App\Models\Lieu;
use App\Models\Ville;
use App\Support\Texte;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

class LieuForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Le lieu')
                    ->columnSpan(1)
                    ->schema([
                        TextInput::make('nom')->required()->maxLength(255),
                        Select::make('type')->options(TypeLieu::class)->required()->default(TypeLieu::Theatre),
                        TextInput::make('label')->label('Label du Ministère')->disabled()->dehydrated(false)
                            ->visible(fn (?Lieu $record): bool => filled($record?->label)),
                        TextInput::make('adresse')->maxLength(255),
                        Grid::make(2)->schema([
                            TextInput::make('code_postal')->maxLength(10),
                            Select::make('ville_id')
                                ->label('Ville')
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $search): array => Ville::query()
                                    ->where('nom_normalise', 'like', Texte::normaliser($search).'%')
                                    ->orderByDesc('population')
                                    ->limit(20)
                                    ->get()
                                    ->mapWithKeys(fn (Ville $ville) => [$ville->id => "{$ville->nom} ({$ville->departement})"])
                                    ->all())
                                ->getOptionLabelUsing(fn ($value): ?string => ($ville = Ville::find($value)) ? "{$ville->nom} ({$ville->departement})" : null),
                        ]),
                        Grid::make(2)->schema([
                            TextInput::make('telephone')->label('Téléphone')->tel()->maxLength(30),
                            TextInput::make('jauge')->integer()->minValue(0),
                        ]),
                        TextInput::make('site_web')->label('Site web')->url()->maxLength(255),
                        Toggle::make('masque')
                            ->label('Masquer ce lieu dans l’app')
                            ->helperText('Masque aussi tous ses spectacles (F7.8). Réversible.'),
                    ]),
                Section::make('Position')
                    ->columnSpan(1)
                    ->schema([
                        ViewField::make('carte')
                            ->hiddenLabel()
                            ->view('filament.carte-lieu')
                            ->dehydrated(false),
                        Text::make('Déplacer le repère sur la carte, ou saisir les coordonnées.'),
                        Grid::make(2)->schema([
                            TextInput::make('latitude')->numeric()->minValue(-90)->maxValue(90)->live(debounce: 500),
                            TextInput::make('longitude')->numeric()->minValue(-180)->maxValue(180)->live(debounce: 500),
                        ]),
                        Select::make('precision_position')
                            ->label('Précision')
                            ->options(PrecisionPosition::class)
                            ->required()
                            ->default(PrecisionPosition::Exacte),
                        Text::make(fn (?Lieu $record): string => $record && filled($record->champs_verrouilles)
                            ? 'Champs corrigés à la main (non écrasés par les imports) : '.implode(', ', $record->champs_verrouilles)
                            : '')
                            ->visible(fn (?Lieu $record): bool => filled($record?->champs_verrouilles)),
                        Text::make(fn (?Lieu $record): string => 'Référence du Ministère : '.$record?->ref_ministere)
                            ->visible(fn (?Lieu $record): bool => filled($record?->ref_ministere)),
                    ]),
            ]);
    }
}
