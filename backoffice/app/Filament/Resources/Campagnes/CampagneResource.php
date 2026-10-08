<?php

namespace App\Filament\Resources\Campagnes;

use App\Enums\StatutCampagne;
use App\Filament\Resources\Campagnes\Pages\ManageCampagnes;
use App\Models\Campagne;
use App\Models\Genre;
use App\Models\Spectacle;
use App\Models\Ville;
use App\Support\Texte;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Campagnes sponsorisées (F6.4, F7.11) : un spectacle, une zone, des goûts ciblés, une période, un nombre d'affichages acheté.
 * Montrée seulement si elle correspond aussi au profil de l'utilisateur ; part maximale de sponsorisé dans les Réglages.
 * Par campagne : affichages, « Voir le spectacle », clics vers la billetterie (F6.5).
 */
class CampagneResource extends Resource
{
    protected static ?string $model = Campagne::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Suggestions';

    protected static ?string $navigationLabel = 'Campagnes';

    protected static ?string $modelLabel = 'campagne';

    protected static ?string $pluralModelLabel = 'campagnes';

    protected static ?string $slug = 'campagnes';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('annonceur_id')->label('Annonceur')->relationship('annonceur', 'nom')->required()->searchable()->preload(),
            Select::make('spectacle_id')->label('Spectacle')->required()->searchable()
                ->getSearchResultsUsing(fn (string $search): array => Spectacle::where('titre_normalise', 'like', '%'.Texte::normaliser($search).'%')
                    ->where('masque', false)->limit(20)->pluck('titre', 'id')->all())
                ->getOptionLabelUsing(fn ($value): ?string => Spectacle::find($value)?->titre),
            Select::make('ville_id')->label('Zone : autour de')->required()->searchable()
                ->getSearchResultsUsing(fn (string $search): array => Ville::where('nom_normalise', 'like', Texte::normaliser($search).'%')
                    ->orderByDesc('est_pilote')->orderByDesc('population')->limit(20)->get()
                    ->mapWithKeys(fn (Ville $v) => [$v->id => "{$v->nom} ({$v->departement})"])->all())
                ->getOptionLabelUsing(fn ($value): ?string => ($v = Ville::find($value)) ? "{$v->nom} ({$v->departement})" : null),
            TextInput::make('zone_rayon_km')->label('Rayon (km)')->integer()->required()->minValue(1)->maxValue(100)->default(20),
            CheckboxList::make('genres')->label('Goûts ciblés')->columns(3)->columnSpanFull()
                ->options(fn (): array => Genre::orderBy('ordre')->pluck('libelle', 'id')->all())
                ->helperText('Aucun coché : tous les goûts. La campagne n’est jamais montrée à quelqu’un dont les goûts ne correspondent pas au spectacle.'),
            DatePicker::make('debut')->label('Du')->required()->default(today()),
            DatePicker::make('fin')->label('Au')->required()->afterOrEqual('debut'),
            TextInput::make('affichages_achetes')->label('Affichages achetés')->integer()->required()->minValue(1),
            Select::make('statut')->options(StatutCampagne::class)->required()->default(StatutCampagne::Brouillon->value),
            FileUpload::make('visuel')->label('Visuel')->image()->disk('public')->directory('campagnes')->maxSize(2048)->columnSpanFull()
                ->helperText('Facultatif : à défaut, l’image du spectacle. 2 Mo au plus.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('debut', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['annonceur', 'spectacle', 'ville'])->withCount([
                'affichages',
                'affichages as fiches_count' => fn ($q) => $q->where('clic_fiche', true),
                'affichages as billetteries_count' => fn ($q) => $q->where('clic_billetterie', true),
            ]))
            ->columns([
                TextColumn::make('statut')->badge(),
                TextColumn::make('spectacle.titre')->label('Spectacle')->weight('bold')->wrap()
                    ->description(fn (Campagne $record): string => $record->annonceur?->nom ?? ''),
                TextColumn::make('zone')->label('Zone')
                    ->state(fn (Campagne $record): string => "{$record->ville?->nom} + {$record->zone_rayon_km} km"),
                TextColumn::make('periode')->label('Période')
                    ->state(fn (Campagne $record): string => $record->debut->format('d/m/Y').' → '.$record->fin->format('d/m/Y')),
                TextColumn::make('affichages_count')->label('Affichages')
                    ->formatStateUsing(fn (int $state, Campagne $record): string => "{$state} / {$record->affichages_achetes}"),
                TextColumn::make('fiches_count')->label('Voir le spectacle')
                    ->formatStateUsing(fn (int $state, Campagne $record): string => self::taux($state, $record->affichages_count)),
                TextColumn::make('billetteries_count')->label('Vers la billetterie')
                    ->formatStateUsing(fn (int $state, Campagne $record): string => self::taux($state, $record->affichages_count)),
            ])
            ->filters([SelectFilter::make('statut')->options(StatutCampagne::class)])
            ->recordActions([
                Action::make('activer')->label('Activer')->icon(Heroicon::OutlinedPlay)->color('success')
                    ->visible(fn (Campagne $record): bool => in_array($record->statut, [StatutCampagne::Brouillon, StatutCampagne::Suspendue], true))
                    ->action(function (Campagne $record): void {
                        $record->update(['statut' => StatutCampagne::Active]);
                        Notification::make()->success()->title('Campagne active')->body('Montrée dans sa période, jusqu’aux affichages achetés.')->send();
                    }),
                Action::make('suspendre')->label('Suspendre')->icon(Heroicon::OutlinedPause)->color('warning')
                    ->visible(fn (Campagne $record): bool => $record->statut === StatutCampagne::Active)
                    ->action(fn (Campagne $record) => $record->update(['statut' => StatutCampagne::Suspendue])),
                EditAction::make(),
            ]);
    }

    private static function taux(int $nombre, int $affichages): string
    {
        return $affichages > 0 ? $nombre.' ('.round(100 * $nombre / $affichages).' %)' : (string) $nombre;
    }

    public static function getPages(): array
    {
        return ['index' => ManageCampagnes::route('/')];
    }
}
