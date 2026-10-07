<?php

namespace App\Filament\Resources\Spectacles;

use App\Filament\Resources\Spectacles\Pages\EditSpectacle;
use App\Filament\Resources\Spectacles\Pages\ListSpectacles;
use App\Filament\Resources\Spectacles\Pages\ViewSpectacle;
use App\Filament\Resources\Spectacles\RelationManagers\OffresRelationManager;
use App\Filament\Resources\Spectacles\RelationManagers\RepresentationsRelationManager;
use App\Models\Genre;
use App\Models\Spectacle;
use App\Support\Texte;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Catalogue des spectacles, alimenté par la collecte. Chaque champ se corrige à la main (verrouillé : la collecte ne l'écrase plus)
 * et un spectacle se masque d'un clic (F7.8, A02).
 */
class SpectacleResource extends Resource
{
    protected static ?string $model = Spectacle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?string $modelLabel = 'spectacle';

    protected static ?string $pluralModelLabel = 'spectacles';

    protected static ?string $recordTitleAttribute = 'titre';

    /** Libellés des champs, pour la mention « corrigé à la main ». */
    public const LIBELLES = [
        'titre' => 'titre', 'description' => 'description', 'genre_id' => 'genre', 'classification_fine' => 'classification',
        'jeune_public' => 'jeune public', 'age_min' => 'âge minimum', 'duree_minutes' => 'durée', 'image_url' => 'image',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('titre')->required()->maxLength(255)->columnSpanFull(),
            Select::make('genre_id')->label('Genre')->options(fn () => Genre::orderBy('ordre')->pluck('libelle', 'id'))->required(),
            TextInput::make('classification_fine')->label('Classification')->maxLength(100),
            Toggle::make('jeune_public')->label('Jeune public'),
            TextInput::make('age_min')->label('Âge minimum')->integer()->minValue(0)->maxValue(18)->suffix('ans'),
            TextInput::make('duree_minutes')->label('Durée')->integer()->minValue(1)->suffix('min'),
            TextInput::make('image_url')->label('Image (adresse)')->url()->maxLength(2000),
            Textarea::make('description')->rows(6)->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('masque')->label('')->badge()->color('danger')->columnSpanFull()
                ->state(fn (Spectacle $record): ?string => $record->masque ? 'Masqué dans l’app' : null)
                ->visible(fn (Spectacle $record): bool => $record->masque),
            TextEntry::make('titre')->columnSpanFull(),
            TextEntry::make('genre.libelle')->label('Genre')->badge(),
            TextEntry::make('classification_fine')->label('Classification')->placeholder('—'),
            IconEntry::make('jeune_public')->label('Jeune public')->boolean(),
            TextEntry::make('duree_minutes')->label('Durée')->suffix(' min')->placeholder('—'),
            TextEntry::make('description')->columnSpanFull()->placeholder('Aucune description'),
            TextEntry::make('corrections')->label('Corrigé à la main (la collecte ne l’écrase plus)')->columnSpanFull()
                ->state(fn (Spectacle $record): ?string => $record->resumeCorrections(self::LIBELLES))
                ->visible(fn (Spectacle $record): bool => filled($record->champs_verrouilles)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('genre')
                ->withCount(['representations as a_venir' => fn (Builder $q) => $q->whereDate('date_locale', '>=', today())])
                ->withCount(['offres as seances_collectees' => fn (Builder $q) => $q->whereNull('disparue_le')->whereDate('date_locale', '>=', today())])
                // Lieux des représentations à venir : pour distinguer les homonymes (deux « Toc Toc », deux troupes).
                ->selectSub(fn ($q) => $q->from('representations as r')->join('lieux as l', 'l.id', '=', 'r.lieu_id')->leftJoin('villes as v', 'v.id', '=', 'l.ville_id')
                    ->whereColumn('r.spectacle_id', 'spectacles.id')->whereRaw('coalesce(r.date_fin, r.date_locale) >= current_date')
                    ->selectRaw("string_agg(distinct l.nom || coalesce(' (' || v.nom || ')', ''), ' ; ')"), 'lieux_a_venir')
                ->selectSub(fn ($q) => $q->from('representations as r')->join('lieux as l', 'l.id', '=', 'r.lieu_id')->leftJoin('villes as v', 'v.id', '=', 'l.ville_id')
                    ->whereColumn('r.spectacle_id', 'spectacles.id')->whereRaw('coalesce(r.date_fin, r.date_locale) >= current_date')
                    ->selectRaw("string_agg(distinct coalesce(v.nom, l.nom), ', ')"), 'villes_a_venir')
                ->withMin(['representations as prochaine' => fn (Builder $q) => $q->whereDate('date_locale', '>=', today())], 'date_locale'))
            ->defaultSort('prochaine')
            ->columns([
                TextColumn::make('titre')
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('titre_normalise', 'like', '%'.Texte::normaliser($search).'%')),
                TextColumn::make('genre.libelle')->label('Genre')->badge(),
                TextColumn::make('lieux_a_venir')->label('Lieux')->wrap()->placeholder('—')
                    ->formatStateUsing(function (?string $state, Spectacle $record): ?string {
                        $lieux = $state === null ? [] : explode(' ; ', $state);

                        return count($lieux) <= 1 ? $state : count($lieux).' lieux : '.mb_strimwidth((string) $record->villes_a_venir, 0, 60, '…');
                    })
                    ->tooltip(fn (Spectacle $record): ?string => $record->lieux_a_venir),
                TextColumn::make('a_venir')->label('Représentations à venir')->numeric(),
                TextColumn::make('seances_collectees')->label('Séances collectées')->numeric()->sortable(),
                TextColumn::make('prochaine')->label('Prochaine')->date('d/m/Y')->sortable()->placeholder('—'),
                IconColumn::make('masque')->label('Masqué')->boolean()->trueIcon(Heroicon::OutlinedEyeSlash)->trueColor('danger')->falseIcon('')->toggleable(),
                TextColumn::make('demo')->label('')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'Démo' : '')->color('gray'),
            ])
            ->filters([
                SelectFilter::make('genre_id')->label('Genre')->options(fn () => Genre::orderBy('ordre')->pluck('libelle', 'id')),
                TernaryFilter::make('demo')->label('Données de démonstration'),
                TernaryFilter::make('masque')->label('Masqués'),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()->label('Corriger')]);
    }

    public static function getRelations(): array
    {
        return [RepresentationsRelationManager::class, OffresRelationManager::class];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSpectacles::route('/'),
            'view' => ViewSpectacle::route('/{record}'),
            'edit' => EditSpectacle::route('/{record}/corriger'),
        ];
    }
}
