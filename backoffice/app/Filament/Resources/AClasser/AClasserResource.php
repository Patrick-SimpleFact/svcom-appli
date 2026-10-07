<?php

namespace App\Filament\Resources\AClasser;

use App\Actions\EnregistrerCorrespondanceGenre;
use App\Enums\FileATraiter;
use App\Enums\StatutElement;
use App\Filament\Resources\AClasser\Pages\ListAClasser;
use App\Filament\Support\Urgence;
use App\Models\ElementATraiter;
use App\Models\Genre;
use App\Models\Source;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * File « À classer » (F7.6, F7.10) : catégories de source inconnues, dont les spectacles sont publiés en « Autres ».
 * « Classer » enregistre la correspondance, qui s'applique tout de suite.
 */
class AClasserResource extends Resource
{
    protected static ?string $model = ElementATraiter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Collecte';

    protected static ?string $navigationLabel = 'À classer';

    protected static ?string $modelLabel = 'catégorie à classer';

    protected static ?string $pluralModelLabel = 'catégories à classer';

    protected static ?string $slug = 'a-classer';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('file', FileATraiter::AClasser)->with('cible');
    }

    public static function getNavigationBadge(): ?string
    {
        $nombre = static::getEloquentQuery()->where('statut', StatutElement::EnAttente)->count();

        return $nombre > 0 ? (string) $nombre : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => Urgence::trier($query))
            ->columns([
                Urgence::colonneUrgence(),
                TextColumn::make('donnees.categorie')->label('Catégorie de la source')->weight('bold')->wrap(),
                TextColumn::make('cible.nom')->label('Source'),
                TextColumn::make('donnees.nb_annonces')->label('Annonces')
                    ->formatStateUsing(fn ($state): string => $state >= 1000 ? '1000 et plus' : (string) $state),
                TextColumn::make('donnees.exemples')->label('Exemples')->listWithLineBreaks()->placeholder('—'),
                TextColumn::make('decision.genre')->label('Classée en')->placeholder('—')
                    ->formatStateUsing(fn (?string $state): ?string => $state ? Genre::firstWhere('slug', $state)?->libelle : null),
                TextColumn::make('statut')->badge()
                    ->color(fn (StatutElement $state): string => $state === StatutElement::EnAttente ? 'warning' : 'gray'),
                TextColumn::make('created_at')->label('Vue le')->dateTime('d/m/Y H:i', 'Europe/Paris'),
            ])
            ->filters([
                SelectFilter::make('statut')->options(StatutElement::class)->default(StatutElement::EnAttente->value),
            ])
            ->recordActions([
                static::actionClasser(),
            ])
            ->recordUrl(null);
    }

    /** Choisir le genre d'une catégorie (aussi dans la boîte de travail). */
    public static function actionClasser(): Action
    {
        return Action::make('classer')->label('Classer')->icon(Heroicon::OutlinedCheck)
            ->visible(fn (ElementATraiter $record): bool => $record->file === FileATraiter::AClasser && $record->statut === StatutElement::EnAttente && $record->cible instanceof Source)
            ->modalHeading(fn (ElementATraiter $record): string => "Classer « {$record->donnees['categorie']} »")
            ->modalDescription('La correspondance sera enregistrée pour cette source et appliquée tout de suite.')
            ->schema([
                Select::make('genre_id')->label('Genre')->options(Genre::orderBy('ordre')->pluck('libelle', 'id'))->required(),
                Toggle::make('jeune_public')->label('Jeune public'),
            ])
            ->action(function (ElementATraiter $record, array $data): void {
                $nombre = app(EnregistrerCorrespondanceGenre::class)->handle(
                    $record->cible, $record->donnees['categorie'], Genre::findOrFail($data['genre_id']), (bool) $data['jeune_public'],
                );

                Notification::make()->success()->title('Catégorie classée')
                    ->body($nombre > 0 ? "{$nombre} spectacle(s) reclassé(s)." : null)->send();
            });
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListAClasser::route('/')];
    }
}
